<template>
  <div class="homepage bg-[var(--color-sand)] font-anuphan selection:bg-[var(--color-accent)] selection:text-white">

    <!-- ══════════════════════════════════════════
         HERO SECTION
    ══════════════════════════════════════════ -->
    <section class="relative h-[90vh] min-h-[700px] w-full flex items-center justify-center overflow-hidden -mt-16">
      <!-- Background Slider -->
      <div class="hero-slider absolute inset-0 overflow-hidden">
        <div
          v-for="(img, index) in heroImages"
          :key="img"
          class="hero-slide absolute inset-0"
          :class="{ 'hero-slide--active': index === currentSlide }"
        >
          <img
            :src="img"
            alt="ลุยเลเขา"
            class="hero-slide-img w-full h-full object-cover"
          />
        </div>
        <div class="hero-slider-glow absolute inset-0 z-[1]" aria-hidden="true"></div>
        <div class="hero-slider-vignette absolute inset-0 z-[2]" aria-hidden="true"></div>
        <!-- Dark Overlay (20-40%) & Gradient -->
        <div class="absolute inset-0 bg-black/30 z-[3]"></div>
        <div class="absolute inset-0 bg-gradient-to-b from-black/20 via-transparent to-[var(--color-sand)]/90 z-[4]"></div>
      </div>

      <!-- Atmospheric orbs -->
      <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-[var(--color-accent-light)]/20 rounded-full blur-[120px] pointer-events-none mix-blend-screen"></div>
      <div class="absolute bottom-1/3 right-1/4 w-[30rem] h-[30rem] bg-[var(--color-gold)]/15 rounded-full blur-[120px] pointer-events-none mix-blend-screen"></div>

      <!-- Content -->
      <div class="hero-content relative z-10 text-center px-4 max-w-6xl w-full pt-16 md:pt-24 pb-12">
        
        <!-- Headline -->
        <h1 class="font-anuphan text-white text-[1.6rem] sm:text-[2rem] md:text-4xl lg:text-[3.2rem] font-extrabold mb-6 leading-[1.25] md:leading-[1.35] tracking-tight drop-shadow-2xl">
          การเที่ยวที่ดี เริ่มจาก<br />
          <span class="text-[var(--color-accent-light)] font-black">ความรู้สึกที่ดี</span>
          ตั้งแต่การจอง
        </h1>
        
        <!-- Subheadline -->
        <p class="text-white/80 text-lg md:text-xl mb-12 font-medium max-w-2xl mx-auto leading-relaxed drop-shadow-md">
          จองทริปดำน้ำ เดินป่า และรถตู้พรีเมียม ครบจบในที่เดียว<br class="hidden md:block" /> รวดเร็ว ปลอดภัย ประทับใจ ไม่ต้องรอ
        </p>

        <!-- Modern Floating Search Bar -->
        <div class="search-bar relative bg-white rounded-[1.8rem] md:rounded-[2rem] shadow-[0_20px_40px_-10px_rgba(0,0,0,0.2)] flex flex-col md:flex-row items-stretch md:items-center p-2 md:p-1.5 gap-2 md:gap-1 max-w-4xl mx-auto border border-white/40 ring-1 ring-black/5 transform transition-all duration-500 hover:shadow-[0_30px_60px_-10px_rgba(0,0,0,0.3)] z-20">
          
          <!-- Trip Selector -->
          <div class="flex items-center flex-1 w-full px-4 py-3 md:py-2.5 bg-gray-50/50 md:bg-transparent hover:bg-gray-100/80 md:hover:bg-gray-50/80 rounded-[1.2rem] md:rounded-[1.5rem] transition-colors group cursor-pointer relative">
            <div class="w-10 h-10 md:w-11 md:h-11 rounded-full bg-[var(--color-primary)]/10 flex items-center justify-center mr-3 group-hover:bg-[var(--color-primary)]/20 transition-colors shadow-inner ring-1 ring-black/5 shrink-0">
              <span class="material-symbols-rounded text-[var(--color-primary)] text-[22px] md:text-[24px]">explore</span>
            </div>
            <div class="flex flex-col items-start min-w-0 flex-1">
              <label class="text-[10px] md:text-[11px] uppercase tracking-widest text-gray-500 font-bold mb-0.5">อยากไปเที่ยวที่ไหน?</label>
              <select
                v-model="selectedTripSlug"
                @change="onTripChange"
                class="bg-transparent border-none focus:ring-0 p-0 text-gray-900 font-extrabold w-full text-sm md:text-base outline-none appearance-none cursor-pointer pr-6"
                :class="selectedTripSlug ? 'text-gray-900' : 'text-gray-400'"
              >
                <option value="">เลือกทริปที่ต้องการ</option>
                <option v-for="t in allTrips" :key="t.id" :value="t.slug">{{ t.title }}</option>
              </select>
              <span class="material-symbols-rounded text-[18px] absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">expand_more</span>
            </div>
          </div>

          <div class="hidden md:block w-px h-12 bg-gray-100 shrink-0"></div>

          <!-- Schedule / Departure Date Selector -->
          <div class="flex items-center flex-1 w-full px-4 py-3 md:py-2.5 bg-gray-50/50 md:bg-transparent hover:bg-gray-100/80 md:hover:bg-gray-50/80 rounded-[1.2rem] md:rounded-[1.5rem] transition-colors group cursor-pointer relative">
            <div class="w-10 h-10 md:w-11 md:h-11 rounded-full bg-[var(--color-accent)]/10 flex items-center justify-center mr-3 group-hover:bg-[var(--color-accent)]/20 transition-colors shadow-inner ring-1 ring-black/5 shrink-0">
              <span v-if="schedulesLoading" class="w-5 h-5 border-2 border-[var(--color-accent)]/30 border-t-[var(--color-accent)] rounded-full animate-spin"></span>
              <span v-else class="material-symbols-rounded text-[var(--color-accent)] text-[22px] md:text-[24px]">calendar_today</span>
            </div>
            <div class="flex flex-col items-start min-w-0 flex-1">
              <label class="text-[10px] md:text-[11px] uppercase tracking-widest text-gray-500 font-bold mb-0.5">รอบวันเดินทาง</label>
              <select
                v-model="selectedScheduleId"
                :disabled="!selectedTripSlug || schedulesLoading"
                class="bg-transparent border-none focus:ring-0 p-0 font-extrabold w-full text-sm md:text-base outline-none appearance-none cursor-pointer pr-6 disabled:cursor-not-allowed"
                :class="selectedScheduleId ? 'text-gray-900' : 'text-gray-400'"
              >
                <option value="">{{ !selectedTripSlug ? 'เลือกวันเดินทาง' : schedulesLoading ? 'กำลังโหลด...' : tripSchedules.length === 0 ? 'ไม่มีรอบว่าง' : 'เลือกวันเดินทาง' }}</option>
                <option v-for="s in tripSchedules" :key="s.id" :value="s.id">
                  {{ formatScheduleOption(s) }}
                </option>
              </select>
              <span class="material-symbols-rounded text-[18px] absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">expand_more</span>
            </div>
          </div>

          <!-- Book / Search Button -->
          <button
            @click="goBook"
            class="bg-[var(--color-primary)] hover:bg-[var(--color-accent)] text-white px-6 py-5 md:py-3.5 rounded-[1.2rem] md:rounded-[1.5rem] font-bold transition-all duration-500 shadow-[0_8px_16px_rgba(45,122,79,0.25)] hover:shadow-[0_12px_24px_rgba(45,122,79,0.4)] hover:-translate-y-0.5 flex items-center justify-center gap-2 whitespace-nowrap shrink-0 cursor-pointer w-full md:w-auto mt-1 md:mt-0"
          >
            <span class="material-symbols-rounded text-[24px]">explore</span>
            <span class="text-xl md:text-base lg:text-lg pr-1">เริ่มเที่ยวเลย</span>
          </button>
        </div>
      </div>

      <!-- Scroll indicator -->
      <div class="absolute bottom-10 left-1/2 -translate-x-1/2 z-10 opacity-70 hover:opacity-100 transition-opacity cursor-pointer">
        <div class="w-7 h-12 border-2 border-white/40 rounded-full flex justify-center pt-2 backdrop-blur-sm">
          <div class="w-1.5 h-3 bg-white rounded-full scroll-dot"></div>
        </div>
      </div>
      
      <!-- Bottom fade -->
      <div class="absolute bottom-0 left-0 w-full h-32 bg-gradient-to-t from-[var(--color-sand)] to-transparent z-0"></div>
    </section>

    <!-- ══════════════════════════════════════════
         SOCIAL PROOF & TRUST SECTION (Redesigned)
    ══════════════════════════════════════════ -->
    <section 
      ref="statsSection"
      class="bg-[var(--color-sand)] relative z-10 pb-28 pt-4"
    >
      <div class="max-w-7xl mx-auto px-6 md:px-8">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 md:gap-8 -mt-20 md:-mt-24 relative z-20">
          <div 
            v-for="(stat, index) in statItems" 
            :key="stat.label" 
            class="bg-white rounded-[2.5rem] p-8 lg:p-10 flex flex-col items-center text-center shadow-[0_20px_50px_rgba(0,0,0,0.04)] border border-gray-100/50 hover:border-[var(--color-primary)]/30 hover:-translate-y-2 hover:shadow-[0_40px_80px_rgba(13,43,30,0.12)] transition-all duration-500 group overflow-hidden relative"
            :class="[isVisible ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-10']"
            :style="{ transitionDelay: `${index * 150}ms` }"
          >
            <!-- Decorative Background Glow -->
            <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-[var(--color-primary)]/5 rounded-full blur-3xl group-hover:bg-[var(--color-primary)]/10 transition-colors duration-500"></div>
            
            <!-- Icon with modern container -->
            <div class="w-16 h-16 rounded-2xl bg-[var(--color-primary)]/5 flex items-center justify-center text-[var(--color-primary)] mb-6 group-hover:bg-[var(--color-primary)] group-hover:text-white group-hover:rotate-[10deg] transition-all duration-500 relative z-10">
              <span class="material-symbols-rounded text-[32px]">{{ stat.icon }}</span>
            </div>
            
            <!-- Statistical Value with Count-up -->
            <div class="flex items-baseline gap-0.5 mb-2 relative z-10">
              <span class="text-4xl lg:text-5xl font-black text-[var(--color-text-dark)] tracking-tight leading-none">
                {{ stat.displayValue }}
              </span>
              <span v-if="stat.suffix" class="text-2xl lg:text-3xl font-black text-[var(--color-accent)]">{{ stat.suffix }}</span>
            </div>
            
            <!-- Persuasive Copy -->
            <div class="text-[13px] lg:text-sm font-bold text-[var(--color-text-muted)] tracking-wide uppercase max-w-[140px] leading-relaxed relative z-10">
              {{ stat.label }}
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         CATEGORIES SECTION
    ══════════════════════════════════════════ -->
    <section class="py-24 bg-[var(--color-sand)] relative overflow-hidden">
      <!-- Decorative background text -->
      <div class="absolute -top-10 -left-10 text-[15rem] font-black text-gray-200/30 select-none pointer-events-none whitespace-nowrap z-0 tracking-tighter">
        ลุยเลเขา
      </div>
      
      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="flex flex-col md:flex-row md:items-end justify-between mb-16 gap-6">
          <div class="max-w-2xl">
            <h2 class="font-anuphan text-4xl md:text-6xl font-extrabold text-[var(--color-text-dark)] tracking-tight leading-[1.1]">เลือกประสบการณ์<br /><span class="text-[var(--color-primary)]">ในแบบของคุณ</span></h2>
          </div>
          <p class="text-[var(--color-text-muted)] text-lg max-w-sm md:text-right font-medium leading-relaxed">
            สัมผัสความงามที่แตกต่างผ่านทริปที่เราคัดสรรมาเพื่อความประทับใจสูงสุดของคุณ
          </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 md:gap-10">
          <router-link
            v-for="cat in categories"
            :key="cat.type"
            :to="`/trips?type=${cat.type}`"
            class="group relative bg-white rounded-[3rem] overflow-hidden shadow-[0_15px_40px_rgba(0,0,0,0.04)] hover:shadow-[0_50px_80px_rgba(0,0,0,0.15)] transition-all duration-500 hover:-translate-y-4 border border-gray-100/50 block isolate h-[520px]"
          >
            <!-- Premium Background Image with Dynamic Zoom -->
            <div class="absolute inset-0 z-[-1] overflow-hidden" :style="`background-color: ${cat.bgColor}`">
              <img 
                :src="cat.image" 
                :alt="cat.label"
                class="w-full h-full object-cover object-center transition-transform duration-[1.5s] ease-out group-hover:scale-110"
              />
              <!-- Enhanced Readability Overlays -->
              <div 
                class="absolute inset-0 bg-gradient-to-t from-black/95 via-black/40 to-transparent transition-opacity duration-500 opacity-70 group-hover:opacity-85"
              ></div>
              <div 
                class="absolute inset-0 mix-blend-soft-light transition-opacity duration-700 opacity-20 group-hover:opacity-40"
                :style="`background: ${cat.color}`"
              ></div>
            </div>
            
            <div class="p-10 md:p-12 flex flex-col h-full relative z-10">
              <!-- Top Badge / Info Row -->
              <div class="flex justify-between items-start mb-auto">
                <div 
                  v-if="cat.isPopular" 
                  class="bg-white/10 backdrop-blur-md border border-white/20 text-white text-[10px] font-black uppercase tracking-[0.2em] px-4 py-2 rounded-full shadow-lg"
                >
                  ยอดนิยม
                </div>
                <!-- Mini Icon Badge -->
                <div class="ml-auto w-12 h-12 rounded-2xl flex items-center justify-center backdrop-blur-md border border-white/20 bg-white/10 text-white shadow-xl transition-transform duration-500 group-hover:rotate-12">
                  <span class="material-symbols-rounded text-2xl">{{ cat.icon }}</span>
                </div>
              </div>
              
              <!-- Content Area with Stagger Effect -->
              <div class="transform transition-all duration-500 group-hover:translate-y-[-10px]">
                <h3 class="text-4xl font-black text-white tracking-tight mb-3 drop-shadow-lg leading-none">
                  {{ cat.label }}
                </h3>
                <p class="text-white/70 text-base font-medium mb-8 leading-relaxed max-w-[90%] transform transition-all duration-500 opacity-80 group-hover:text-white group-hover:opacity-100">
                  {{ cat.subtext }}
                </p>
                
                <!-- Category-Specific Actionable CTA -->
                <div class="inline-flex items-center gap-4 text-sm font-black text-white uppercase tracking-widest bg-white/10 backdrop-blur-xl px-8 py-4 rounded-2xl border border-white/30 shadow-[0_10px_30px_rgba(0,0,0,0.2)] hover:bg-white hover:text-[var(--color-primary)] hover:border-white transition-all duration-500 group/btn">
                  <span>{{ cat.ctaText }}</span>
                  <div class="w-6 h-6 rounded-full bg-white/10 flex items-center justify-center group-hover/btn:bg-[var(--color-primary)] transition-colors duration-300">
                    <span class="material-symbols-rounded text-lg transform group-hover:translate-x-1 transition-transform">arrow_right_alt</span>
                  </div>
                </div>
              </div>
            </div>
          </router-link>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         POPULAR EXPERIENCES SECTION
    ══════════════════════════════════════════ -->
    <section class="py-24 relative overflow-hidden bg-white">
      <!-- Decorative Background Image -->
      <div class="absolute inset-0 z-0 pointer-events-none opacity-50">
        <img 
          src="/images/experience_overlay.webp" 
          alt="" 
          class="w-full h-full object-cover"
        />
      </div>

      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="flex flex-col md:flex-row justify-between items-end mb-16 gap-6">
          <div>
            <span class="text-[var(--color-accent)] font-bold tracking-wider uppercase text-sm mb-2 block">ทริปยอดฮิต</span>
            <h2 class="font-anuphan text-4xl md:text-5xl font-extrabold text-[var(--color-text-dark)] tracking-tight">ประสบการณ์ยอดนิยม</h2>
          </div>
          <router-link
            to="/trips"
            class="group inline-flex items-center justify-center gap-2 px-6 py-3 rounded-full border-2 border-gray-100 hover:border-[var(--color-accent)] text-[var(--color-text-dark)] hover:text-[var(--color-accent)] font-bold transition-all duration-300"
          >
            ดูทั้งหมด
            <span class="material-symbols-rounded text-[20px] group-hover:translate-x-1 transition-transform duration-300">arrow_right_alt</span>
          </router-link>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="flex justify-center py-20">
          <div class="flex flex-col items-center gap-5">
            <div class="w-12 h-12 border-4 border-[var(--color-sand)] border-t-[var(--color-accent)] rounded-full animate-spin"></div>
            <span class="text-[var(--color-text-muted)] font-bold tracking-wide">กำลังโหลดทริป...</span>
          </div>
        </div>

        <!-- Cards Grid -->
        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
          <router-link
            v-for="trip in trips"
            :key="trip.id"
            :to="`/trips/${trip.slug}`"
            class="group flex flex-col bg-white rounded-[2rem] overflow-hidden border border-gray-100 hover:border-transparent hover:shadow-[0_20px_40px_rgba(0,0,0,0.08)] transition-all duration-300 transform hover:-translate-y-2"
          >
            <!-- Image Container -->
            <div class="relative overflow-hidden aspect-[4/5] m-2 rounded-[1.5rem]">
              <img
                v-if="trip.thumbnail_image || trip.cover_image"
                :src="trip.thumbnail_image || trip.cover_image"
                :alt="trip.title"
                class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
                @error="(e) => e.target.style.display='none'"
              />
              <div v-else class="w-full h-full bg-gray-100 flex items-center justify-center">
                <span class="material-symbols-rounded text-gray-300 text-5xl">image</span>
              </div>
              
              <!-- Gradient Overlay -->
              <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-60 group-hover:opacity-80 transition-opacity duration-300"></div>
              
              <!-- Badges -->
              <div class="absolute top-4 left-4 flex flex-col gap-2">
                <span
                  class="px-3 py-1.5 rounded-full text-xs font-black tracking-wide shadow-lg backdrop-blur-md"
                  :class="typeBadgeClass(trip.type)"
                >
                  {{ typeLabel(trip.type) }}
                </span>
              </div>
              
              <!-- Wishlist -->
              <button
                class="absolute top-4 right-4 w-9 h-9 flex items-center justify-center transition-all duration-300 rounded-full border-2 cursor-pointer active:scale-75 backdrop-blur-[2px] z-20"
                :class="wishlistStore.isFavorite(trip.id) 
                  ? 'border-red-500 text-red-500 bg-white/10 shadow-sm' 
                  : 'border-white/60 text-white hover:border-white hover:bg-black/10 shadow-sm'"
                @click.prevent="wishlistStore.toggleFavorite(trip)"
                aria-label="บันทึกรายการโปรด"
              >
                <span 
                  class="material-symbols-rounded text-[20px]"
                  :style="wishlistStore.isFavorite(trip.id) ? 'font-variation-settings:\'FILL\' 1' : ''"
                >
                  favorite
                </span>
              </button>
              
              <!-- Location / Duration indicator -->
              <div class="absolute bottom-4 left-4 right-4 flex justify-between items-center text-white">
                <div class="flex items-center gap-1.5 bg-black/30 backdrop-blur-md px-3 py-1.5 rounded-full">
                  <span class="material-symbols-rounded text-[14px]">schedule</span>
                  <span class="text-xs font-bold">{{ trip.duration_days || 1 }} วัน</span>
                </div>
              </div>
            </div>
            
            <!-- Info -->
            <div class="p-5 flex-1 flex flex-col">
              <div class="flex items-center gap-1.5 mb-2">
                <div class="flex text-[#FFB020] gap-0.5">
                  <span class="material-symbols-rounded text-[16px]" style="font-variation-settings:'FILL' 1">star</span>
                </div>
                <template v-if="trip.review_count > 0">
                  <span class="text-[var(--color-text-dark)] font-bold text-sm">{{ Number(trip.rating).toFixed(1) }}</span>
                  <span class="text-gray-400 text-xs font-medium">({{ trip.review_count }} รีวิว)</span>
                </template>
                <div v-if="trip.confirmed_passengers_count > 0" class="ml-auto flex items-center gap-1 text-[var(--color-accent)] font-bold text-xs bg-[var(--color-accent-light)]/10 px-2 py-0.5 rounded-full">
                  <span class="material-symbols-rounded text-[14px]">group</span>
                  <span>{{ trip.confirmed_passengers_count }} คน</span>
                </div>
                <span v-else class="text-gray-400 text-xs font-medium italic">ยังไม่มีรีวิว</span>
              </div>
              
              <h4 class="text-[1.1rem] font-extrabold text-[var(--color-text-dark)] mb-2 group-hover:text-[var(--color-accent)] transition-colors duration-300 leading-snug line-clamp-2">
                {{ trip.title }}
              </h4>
              
              <div class="mt-auto pt-4 flex justify-between items-end border-t border-gray-100">
                <div class="flex flex-col">
                  <span class="text-xs text-[var(--color-text-muted)] font-bold uppercase tracking-wider mb-0.5">
                    {{ Number(trip.min_price) != Number(trip.max_price) ? 'ช่วงราคา' : 'เริ่มต้น' }}
                  </span>
                  <div class="flex items-baseline gap-1">
                    <template v-if="Number(trip.min_price) != Number(trip.max_price)">
                      <span class="text-xl font-black text-[var(--color-text-dark)]">฿{{ Number(trip.min_price).toLocaleString() }} - {{ Number(trip.max_price).toLocaleString() }}</span>
                    </template>
                    <template v-else>
                      <span class="text-xl font-black text-[var(--color-text-dark)]">฿{{ Number(trip.min_price).toLocaleString() }}</span>
                    </template>
                  </div>
                </div>
                <div class="w-10 h-10 rounded-full bg-[var(--color-sand)] flex items-center justify-center group-hover:bg-[var(--color-accent)] group-hover:text-white transition-colors duration-300">
                  <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
                </div>
              </div>
            </div>
          </router-link>
        </div>

        <!-- View all CTA -->
        <div class="text-center mt-16">
          <router-link
            to="/trips"
            class="inline-flex items-center gap-2 bg-[var(--color-primary)] hover:bg-[var(--color-accent)] text-white px-10 py-4 rounded-full font-bold text-lg transition-all duration-300 shadow-[0_10px_20px_rgba(0,0,0,0.1)] hover:shadow-[0_15px_30px_rgba(45,122,79,0.3)] hover:-translate-y-1 cursor-pointer"
          >
            ค้นหากิจกรรมทั้งหมด
            <span class="material-symbols-rounded text-[24px]">explore</span>
          </router-link>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         FEATURED / ASYMMETRIC SECTION
    ══════════════════════════════════════════ -->
    <section class="py-24 bg-[var(--color-primary)] relative overflow-hidden" v-if="featuredTrips.length > 0">
      <!-- Decorative Background Image -->
      <div class="absolute inset-0 z-0 pointer-events-none opacity-20">
        <img 
          src="/images/recommend_overlay.webp" 
          alt="" 
          class="w-full h-full object-cover"
        />
      </div>
      
      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="mb-14 flex flex-col md:flex-row justify-between items-end gap-6">
          <div>
            <span class="text-[var(--color-accent-light)] font-bold tracking-wider uppercase text-sm mb-2 block">คัดสรรพิเศษ</span>
            <h2 class="font-anuphan text-4xl md:text-5xl font-extrabold text-white tracking-tight">แนะนำสำหรับคุณ</h2>
          </div>
          <p class="text-white/70 max-w-sm md:text-right font-medium">
            ประสบการณ์สุดพิเศษที่เราอยากให้คุณได้ลองสัมผัสด้วยตัวเอง
          </p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-stretch">
          <!-- Main Hero Featured Card (Large) -->
          <router-link
            :to="`/trips/${featuredTrips[0].slug}`"
            class="lg:col-span-8 group relative overflow-hidden rounded-[3rem] shadow-2xl h-[550px] lg:h-[650px] cursor-pointer block isolate"
          >
            <!-- Premium Image Background with Zoom -->
            <img
              :src="featuredTrips[0].cover_image || '/images/placeholder.jpg'"
              :alt="featuredTrips[0].title"
              class="absolute inset-0 w-full h-full object-cover transition-transform duration-[2s] ease-out group-hover:scale-110"
            />
            <!-- Multiple Overlay Layers for Depth & Contrast -->
            <div class="absolute inset-0 bg-gradient-to-t from-black/95 via-black/30 to-transparent opacity-80 group-hover:opacity-90 transition-opacity duration-700"></div>
            <div class="absolute inset-0 ring-1 ring-inset ring-white/10 rounded-[3rem]"></div>
            
            <div class="absolute inset-0 p-10 md:p-14 flex flex-col justify-end">
              <div class="flex flex-wrap items-center gap-3 mb-8 transform transition-transform duration-500 group-hover:-translate-y-2">
                <span class="bg-[var(--color-accent-light)] text-white px-5 py-2 rounded-full text-[10px] font-black uppercase tracking-[0.2em] shadow-xl">{{ typeLabel(featuredTrips[0].type) }}</span>
                
                <!-- Smart Rating / New Badge -->
                <span v-if="Number(featuredTrips[0].rating) > 0" class="bg-white/10 backdrop-blur-md text-white px-5 py-2 rounded-full text-[10px] font-black shadow-xl flex items-center gap-2 border border-white/10">
                  <span class="material-symbols-rounded text-[16px] text-[#FFB020]" style="font-variation-settings:'FILL' 1">star</span>
                  {{ Number(featuredTrips[0].rating).toFixed(1) }}
                </span>
                <span v-else class="bg-white text-[var(--color-primary)] px-5 py-2 rounded-full text-[10px] font-black uppercase tracking-[0.2em] shadow-xl border border-white">
                  Trip ใหม่ ✨
                </span>
              </div>
              <div class="max-w-2xl mb-10 transform transition-transform duration-700 group-hover:-translate-y-2">
                <h3 class="font-anuphan text-white text-4xl md:text-6xl font-black mb-5 leading-[1.1] tracking-tight group-hover:text-white transition-colors drop-shadow-2xl">
                  {{ featuredTrips[0].title }}
                </h3>
                <p class="text-white/70 text-lg md:text-xl font-medium leading-relaxed drop-shadow-lg line-clamp-2 max-w-xl group-hover:text-white/90 transition-colors">
                  {{ featuredTrips[0].description || featuredTrips[0].location }}
                </p>
              </div>
              
              <div class="flex items-center justify-between flex-wrap gap-8 mt-auto border-t border-white/10 pt-10">
                <div class="flex flex-col">
                  <div class="flex items-baseline gap-2">
                    <span class="text-white/70 text-sm font-bold">
                      {{ Number(featuredTrips[0].min_price) != Number(featuredTrips[0].max_price) ? 'ช่วงราคา' : 'เริ่มต้นเพียง' }}
                    </span>
                    <span v-if="Number(featuredTrips[0].min_price) != Number(featuredTrips[0].max_price)" class="text-white text-3xl md:text-5xl font-black tracking-tighter">
                      ฿{{ Number(featuredTrips[0].min_price).toLocaleString('th-TH') }} - {{ Number(featuredTrips[0].max_price).toLocaleString('th-TH') }}
                    </span>
                    <span v-else class="text-white text-4xl md:text-5xl font-black tracking-tighter">
                      ฿{{ Number(featuredTrips[0].min_price).toLocaleString('th-TH') }}
                    </span>
                  </div>
                </div>
                
                <div class="bg-white text-[var(--color-primary)] hover:bg-[var(--color-accent-light)] hover:text-white px-10 py-5 rounded-2xl font-black text-lg shadow-[0_20px_40px_rgba(0,0,0,0.3)] transition-all duration-500 flex items-center gap-3 group/btn hover:-translate-y-1">
                  <span>สำรวจทริปนี้</span>
                  <div class="w-8 h-8 rounded-full bg-[var(--color-primary)]/10 flex items-center justify-center group-hover/btn:bg-white/20 transition-colors">
                    <span class="material-symbols-rounded text-[24px] transform group-hover/btn:translate-x-1 transition-transform">arrow_forward</span>
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
              class="flex-1 group relative overflow-hidden rounded-[2.5rem] bg-gray-900 cursor-pointer block p-8 md:p-10 flex flex-col justify-between hover:shadow-2xl transition-all duration-500 transform hover:-translate-y-2 isolate"
            >
              <!-- Background Image -->
               <img
                :src="trip.thumbnail_image || trip.cover_image || '/images/placeholder.jpg'"
                :alt="trip.title"
                class="absolute inset-0 w-full h-full object-cover transition-transform duration-[2s] group-hover:scale-110 opacity-60"
              />
              <!-- Consistent Premium Overlay -->
              <div class="absolute inset-0 bg-gradient-to-t from-black/95 via-black/20 to-transparent group-hover:from-black transition-all duration-700"></div>
              
              <div class="relative z-10 transform transition-transform duration-500 group-hover:-translate-y-2">
                <div class="flex justify-between items-start mb-8">
                  <div class="flex gap-2">
                    <span class="px-4 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest backdrop-blur-md border border-white/20 bg-white/10 text-white">
                      {{ typeLabel(trip.type) }}
                    </span>
                    <span v-if="idx === 0" class="px-4 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest bg-[var(--color-accent)] text-white shadow-lg">ยอดนิยม</span>
                  </div>
                  <div class="w-12 h-12 rounded-2xl flex items-center justify-center backdrop-blur-md border border-white/20 bg-white/10 text-white shadow-xl transition-transform duration-500 group-hover:rotate-12">
                    <span class="material-symbols-rounded text-[24px]">{{ typeFeaturedIcon(trip.type) }}</span>
                  </div>
                </div>
                
                <h4 class="text-2xl md:text-3xl font-black text-white mb-3 group-hover:text-[var(--color-accent-light)] transition-colors leading-tight drop-shadow-md">
                  {{ trip.title }}
                </h4>
                <div v-if="Number(trip.rating) > 0" class="flex items-center gap-1.5 mb-2">
                  <span class="material-symbols-rounded text-[14px] text-[#FFB020]" style="font-variation-settings:'FILL' 1">star</span>
                  <span class="text-white/80 text-xs font-bold">{{ Number(trip.rating).toFixed(1) }} Rating</span>
                </div>
              </div>
              
              <div class="relative z-10 pt-8 mt-4 border-t border-white/10 flex justify-between items-end">
                <div class="flex flex-col">
                  <span class="text-[10px] text-white/40 font-black uppercase tracking-[0.2em] mb-1">
                    {{ Number(trip.min_price) != Number(trip.max_price) ? 'ช่วงราคา' : 'เริ่มต้นที่' }}
                  </span>
                  <span v-if="Number(trip.min_price) != Number(trip.max_price)" class="text-xl font-black text-white tracking-tight">
                    ฿{{ Number(trip.min_price).toLocaleString('th-TH') }} - {{ Number(trip.max_price).toLocaleString('th-TH') }}
                  </span>
                  <span v-else class="text-2xl font-black text-white tracking-tight">
                    ฿{{ Number(trip.min_price).toLocaleString('th-TH') }}
                  </span>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-white/10 backdrop-blur-md border border-white/20 flex items-center justify-center group-hover:bg-white group-hover:text-[var(--color-primary)] transition-all duration-500 shadow-xl">
                  <span class="material-symbols-rounded text-[28px]">arrow_outward</span>
                </div>
              </div>
            </router-link>

            <!-- Sophisticated Empty State -->
            <router-link
              v-if="featuredTrips.length === 1"
              to="/trips"
              class="flex-1 group cursor-pointer bg-white/5 backdrop-blur-md rounded-[2.5rem] p-10 flex flex-col items-center justify-center hover:bg-white/10 transition-all duration-500 border-2 border-dashed border-white/20 text-center gap-6"
            >
              <div class="w-20 h-20 rounded-full bg-white/10 flex items-center justify-center text-white group-hover:scale-110 group-hover:bg-[var(--color-accent-light)] transition-all duration-500">
                <span class="material-symbols-rounded text-[40px]">explore</span>
              </div>
              <div>
                <p class="text-white text-xl font-black tracking-tight mb-2">ดูทริปแนะนำทั้งหมด</p>
                <p class="text-white/50 text-sm font-medium">ค้นหาแรงบันดาลใจครั้งใหม่ของคุณที่นี่</p>
              </div>
            </router-link>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         TESTIMONIALS SECTION
    ══════════════════════════════════════════ -->
    <section class="py-24 bg-[var(--color-sand)] relative">
      <div class="max-w-7xl mx-auto px-6 md:px-8">
        <div class="text-center mb-16">
          <span class="text-[var(--color-accent)] font-bold tracking-wider uppercase text-sm mb-2 block">รีวิวจากลูกค้า</span>
          <h2 class="font-anuphan text-4xl md:text-5xl font-extrabold text-[var(--color-text-dark)] tracking-tight">นักเดินทางพูดถึงเรา</h2>
        </div>
        
        <div v-if="reviews.length > 0" class="grid grid-cols-1 md:grid-cols-3 gap-8">
          <div
            v-for="review in reviews"
            :key="review.id"
            class="bg-white rounded-[2rem] p-8 shadow-[0_10px_30px_rgba(0,0,0,0.03)] hover:shadow-[0_20px_40px_rgba(0,0,0,0.08)] transition-shadow duration-300 relative border border-gray-100"
          >
            <!-- Quote Icon -->
            <div class="absolute top-8 right-8 text-gray-100">
              <span class="material-symbols-rounded text-[64px]" style="font-variation-settings:'FILL' 1">format_quote</span>
            </div>
            
            <div class="relative z-10">
              <div class="flex text-[#FFB020] gap-1 mb-6">
                <span v-for="n in 5" :key="n" class="material-symbols-rounded text-[20px]" 
                  :class="n <= review.rating ? 'text-[#FFB020]' : 'text-gray-200'"
                  :style="n <= review.rating ? 'font-variation-settings:\'FILL\' 1' : ''">
                  star
                </span>
              </div>
              
              <p class="text-[var(--color-text-mid)] text-base font-medium leading-relaxed mb-8 italic line-clamp-4">"{{ review.comment || 'ไม่มีความคิดเห็น' }}"</p>
              
              <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-[var(--color-sand)] rounded-full flex items-center justify-center text-[var(--color-accent)] font-black text-lg overflow-hidden border-2 border-white shadow-sm ring-1 ring-gray-100">
                  <img 
                    v-if="review.user_avatar || review.user?.avatar_url || review.user?.avatar" 
                    :src="review.user_avatar || review.user?.avatar_url || review.user?.avatar" 
                    class="w-full h-full object-cover" 
                  />
                  <span v-else>{{ review.user_name?.charAt(0) }}</span>
                </div>
                <div class="min-w-0">
                  <div class="font-extrabold text-[var(--color-text-dark)] text-base truncate">{{ review.user_name }}</div>
                  <div class="text-[var(--color-text-muted)] text-sm font-medium truncate">{{ review.trip_title }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="text-center py-12 bg-white/50 rounded-[2rem] border-2 border-dashed border-gray-200">
          <p class="text-[var(--color-text-muted)] font-bold">ยังไม่มีรีวิวจากนักเดินทาง</p>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         WHY ANDAMAN LUXE SECTION
    ══════════════════════════════════════════ -->
    <section class="py-32 bg-white relative overflow-hidden">
      <!-- Decorative background elements -->
      <div class="absolute top-0 right-0 w-96 h-96 bg-[var(--color-primary)]/5 rounded-full blur-3xl -mr-48 -mt-48 pointer-events-none"></div>
      <div class="absolute bottom-0 left-0 w-96 h-96 bg-[var(--color-accent)]/5 rounded-full blur-3xl -ml-48 -mb-48 pointer-events-none"></div>

      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="text-center max-w-3xl mx-auto mb-20">
          <h2 class="font-anuphan text-4xl md:text-5xl font-black text-[var(--color-text-dark)] tracking-tight mb-6">ทำไมต้องก้าวไปกับ <span class="text-[var(--color-primary)]">ลุยเลเขา?</span></h2>
          <p class="text-[var(--color-text-muted)] text-lg font-medium leading-relaxed">
            เราไม่ได้อยากเป็นแค่แพลตฟอร์มจองทริปแต่อยากเป็น "เพื่อน"<br class="hidden md:block" /> ที่ช่วยให้คุณออกไปเที่ยวได้ง่ายขึ้น และมีความสุขมากขึ้นในทุกการเดินทางที่คุณเลือกไปกับเรา
          </p>
        </div>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8 md:gap-10 mt-16 md:mt-12 items-stretch">
          <div
            v-for="(trust, idx) in trustItems"
            :key="trust.title"
            class="bg-white p-8 md:p-10 pt-28 md:pt-32 rounded-[3rem] shadow-lg hover:shadow-2xl transition-all duration-500 border border-[var(--color-sand-dark)] hover:border-[var(--color-accent)]/30 cursor-default group relative mt-24 text-center flex flex-col justify-between"
            :class="{ 'lg:translate-y-8': idx % 2 === 1 }"
          >
            <!-- Image Wrapper (Out-of-bound) -->
            <div class="absolute -top-24 md:-top-28 left-1/2 -translate-x-1/2 z-20 transition-all duration-700 group-hover:scale-110 group-hover:-translate-y-4">
              <img 
                :src="trust.image" 
                :alt="trust.title" 
                class="w-48 h-48 md:w-56 md:h-56 max-w-none object-contain drop-shadow-2xl"
              />
            </div>

            <div class="relative z-10 flex flex-col items-center h-full">
              <h3 class="text-2xl font-bold text-[var(--color-text-dark)] mb-4 group-hover:text-[var(--color-accent)] transition-colors">{{ trust.title }}</h3>
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
      
      <!-- Premium UI Decorations -->
      <div class="absolute top-0 right-0 w-[42rem] h-[42rem] bg-[var(--color-accent)]/16 rounded-full blur-[110px] -translate-y-1/2 translate-x-1/4 pointer-events-none"></div>
      <div class="absolute bottom-0 left-0 w-[30rem] h-[30rem] bg-white/5 rounded-full blur-[90px] translate-y-1/2 -translate-x-1/4 pointer-events-none"></div>

      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="reveal-section max-w-3xl">
          <div class="inline-flex items-center gap-3 text-[var(--color-gold)] font-bold tracking-wider uppercase text-xs md:text-sm mb-6">
            <span class="w-10 h-[2px] bg-[var(--color-gold)]"></span>
            พร้อมจะออกไปลุยหรือยัง?
          </div>
          
          <h2 class="text-3xl sm:text-4xl md:text-5xl lg:text-6xl font-black text-white leading-[1.08] mb-7 tracking-tight">
            ให้เราช่วยสร้าง<br/>
            <span class="text-transparent bg-clip-text bg-gradient-to-r from-white to-white/70 underline decoration-[var(--color-gold)] decoration-4 md:decoration-6">ความทรงจำ</span> ที่สวยงามของคุณ
          </h2>
          
          <p class="text-base md:text-lg text-white/80 leading-relaxed font-medium mb-10 max-w-xl border-l-4 border-[var(--color-gold)] pl-5 md:pl-6">
            เราดูแลทุกรายละเอียด เพื่อให้คุณได้ใช้เวลาที่มีค่าไปกับการ "ซึมซับบรรยากาศ" และ "สนุกกับการเดินทาง" อย่างเต็มที่
          </p>
          
          <div class="flex flex-col sm:flex-row gap-4">
            <router-link
              to="/trips"
              class="inline-flex items-center justify-center gap-3 bg-[var(--color-accent)] text-white px-7 md:px-9 py-4 rounded-[1.5rem] text-base md:text-lg font-black hover:bg-[#3D8F66] hover:shadow-[0_16px_40px_rgba(76,175,125,0.35)] transition-all duration-500 group/btn"
            >
              <span>เริ่มผจญภัยกับเราวันนี้</span>
              <span class="material-symbols-rounded text-2xl group-hover/btn:translate-x-1.5 transition-transform">explore</span>
            </router-link>
            <router-link
              to="/about"
              class="inline-flex items-center justify-center gap-3 bg-white/10 backdrop-blur-md border border-white/20 text-white px-7 md:px-9 py-4 rounded-[1.5rem] text-base md:text-lg font-black hover:bg-white/20 transition-all duration-500"
            >
              รู้จักเรามากขึ้น
            </router-link>
          </div>
          
          <!-- Quick Status -->
          <div class="mt-12 flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-8 opacity-70">
            <div class="flex items-center gap-2.5">
              <span class="material-symbols-rounded text-white text-[20px]">verified_user</span>
              <span class="font-bold text-white text-sm md:text-base">จองปลอดภัย 100%</span>
            </div>
            <div class="flex items-center gap-2.5">
              <span class="material-symbols-rounded text-white text-[20px]">thumb_up</span>
              <span class="font-bold text-white text-sm md:text-base">พาร์ทเนอร์ที่ผ่านการตรวจสอบ</span>
            </div>
          </div>
        </div>
      </div>
    </section>


  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import api from '../lib/axios';
import { useWishlistStore } from '../stores/wishlist';

const wishlistStore = useWishlistStore();
const router = useRouter();

const heroImages = ref([
  '/images/phusoidao.webp',
  '/images/snorkel.webp',
  '/images/phukradueng.webp',
  '/images/landscape.webp',
  '/images/khaochangphueak.webp',
  '/images/hiking.webp',
]);
const HERO_SLIDE_INTERVAL_MS = 6500;
const currentSlide = ref(0);
const prefersReducedMotion = ref(false);
let sliderInterval = null;

const trips = ref([]);
const featuredTrips = ref([]);
const loading = ref(true);




// Search bar state
const allTrips = ref([]);
const selectedTripSlug = ref('');
const tripSchedules = ref([]);
const selectedScheduleId = ref('');
const schedulesLoading = ref(false);

function formatScheduleOption(s) {
  const formatDateTh = (date) => {
    if (!date) return '';
    return new Date(date).toLocaleDateString('th-TH', { 
      day: 'numeric', 
      month: 'short', 
      year: 'numeric' 
    });
  };
  
  const depStr = formatDateTh(s.departure_date);
  const retStr = formatDateTh(s.return_date);
  
  if (retStr && retStr !== depStr) {
    return `${depStr} - ${retStr}`;
  }
  return depStr;
}

async function onTripChange() {
  selectedScheduleId.value = '';
  tripSchedules.value = [];
  if (!selectedTripSlug.value) return;
  schedulesLoading.value = true;
  try {
    const res = await api.get(`/trips/${selectedTripSlug.value}/schedules`);
    const allSchedules = (res.data.data || []).filter(s => s.available_seats > 0);
    
    // Filter to unique dates (only show one per date range)
    const uniqueSchedules = [];
    const seenDates = new Set();
    
    allSchedules.forEach(s => {
      const dateKey = `${s.departure_date}_${s.return_date}`;
      if (!seenDates.has(dateKey)) {
        seenDates.add(dateKey);
        uniqueSchedules.push(s);
      }
    });
    
    tripSchedules.value = uniqueSchedules;
  } catch (e) {
    console.error('Failed to load schedules', e);
  } finally {
    schedulesLoading.value = false;
  }
}

const goBook = () => {
  if (selectedTripSlug.value) {
    // Navigate to trip detail page with optional schedule query
    router.push({
      path: `/trips/${selectedTripSlug.value}`,
      query: selectedScheduleId.value ? { schedule: selectedScheduleId.value } : {}
    });
  } else {
    router.push('/trips');
  }
};

const statsSection = ref(null);
const isVisible = ref(false);

const statItems = ref([
  { 
    icon: 'groups', 
    target: 1420, 
    displayValue: '0', 
    suffix: '+', 
    label: 'นักเดินทางที่ไว้ใจก้าวไปกับเรา' 
  },
  { 
    icon: 'star', 
    target: 4.9, 
    displayValue: '0.0', 
    suffix: '/5', 
    label: 'คะแนนรีวิวจากความประทับใจ' 
  },
  { 
    icon: 'map', 
    target: 24, 
    displayValue: '0', 
    suffix: '+', 
    label: 'เส้นทางท่องเที่ยวที่คัดสรรมาเพื่อคุณ' 
  },
  { 
    icon: 'verified_user', 
    target: 99.9, 
    displayValue: '0', 
    suffix: '%', 
    label: 'อัตราความปลอดภัยระดับพรีเมียม' 
  },
]);

const animateStats = () => {
  statItems.value.forEach((stat) => {
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

const categories = [
  {
    type: 'snorkeling',
    label: 'Snorkeling',
    subtext: 'สำรวจโลกใต้ทะเลที่สวยที่สุดในอันดามัน พร้อมทีมงานมืออาชีพ',
    ctaText: 'ดูทริปดำน้ำ',
    image: '/images/diving_show.webp',
    icon: 'scuba_diving',
    color: '#3B9DD4',
    bgColor: '#E8F4FA',
    isPopular: true,
  },
  {
    type: 'trekking',
    label: 'Trekking',
    subtext: 'ผจญภัยสู่ยอดเขาและเส้นทางธรรมชาติที่ยังไม่ถูกรบกวน',
    ctaText: 'สำรวจเส้นทาง',
    image: '/images/hiking_show.webp',
    icon: 'hiking',
    color: '#2D7A4F',
    bgColor: '#E8F5EC',
    isPopular: false,
  },
  {
    type: 'climbing',
    label: 'Premium Van',
    subtext: 'เดินทางระดับ Exclusive พร้อมความสะดวกสบายครบครันทุกเส้นทาง',
    ctaText: 'ดูแพ็กเกจทัวร์',
    image: '/images/van_show.webp',
    icon: 'airport_shuttle',
    color: '#C8963E',
    bgColor: '#FFF8EE',
    isPopular: false,
  },
];

const reviews = ref([]);

const trustItems = [
  {
    image: '/images/travel_safety.webp',
    title: 'ความปลอดภัยสูงสุด',
    desc: 'เราตรวจสอบอุปกรณ์ 100% ทุกครั้งก่อนออกเดินทาง เพื่อให้แน่ใจว่าคุณจะปลอดภัยตลอดทริป',
  },
  {
    image: '/images/247_support.webp',
    title: 'ผู้ดูแลส่วนตัว 24/7',
    desc: 'ทีมงานมืออาชีพพร้อมให้ความช่วยเหลือคุณทุกนาที ไม่ว่าจะเป็นการจองหรือช่วยเหลือหน้างาน',
  },
  {
    image: '/images/nature_travel.webp',
    title: 'ท่องเที่ยวสายอนุรักษ์',
    desc: 'ทุกทริปของเรามุ่งเน้นความยั่งยืน สนับสนุนชุมชนท้องถิ่นและอนุรักษ์ธรรมชาติอย่างจริงจัง',
  },
  {
    image: '/images/nohidden_show.webp',
    title: 'ราคาโปร่งใส No Hidden',
    desc: 'ราคาสุทธิที่แจ้งคือราคาที่คุณต้องจ่ายจริง ไม่มีค่าธรรมเนียมแอบแฝง จ่ายครั้งเดียวจบ',
  },
];

/* Category color mapping */
const typeMap = {
  trekking:   { label: 'เดินป่า',    class: 'bg-[#2D7A4F] text-white' },
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

onUnmounted(() => {
  clearInterval(sliderInterval);
  if (window.Tawk_API && typeof window.Tawk_API.hideWidget === 'function') {
    window.Tawk_API.hideWidget();
  }
});

onMounted(async () => {
  if (typeof window !== 'undefined') {
    prefersReducedMotion.value = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  // Fetch hero slides from API; keep static fallback if API returns empty
  try {
    const heroRes = await api.get('/hero-slides');
    const apiSlides = (heroRes.data.data ?? heroRes.data) || [];
    if (apiSlides.length) {
      heroImages.value = apiSlides.map((s) => s.image_url);
    }
  } catch {
    // keep static fallback
  }

  if (!prefersReducedMotion.value && heroImages.value.length > 1) {
    sliderInterval = setInterval(() => {
      currentSlide.value = (currentSlide.value + 1) % heroImages.value.length;
    }, HERO_SLIDE_INTERVAL_MS);
  }

  try {
    const [tripsRes, featuredRes, reviewsRes, statsRes, allTripsRes] = await Promise.all([
      api.get('/trips', { params: { per_page: 8 } }),
      api.get('/trips/featured'),
      api.get('/reviews', { params: { per_page: 3 } }),
      api.get('/stats'),
      api.get('/trips', { params: { per_page: 100 } }),
    ]);
    trips.value = tripsRes.data.data;
    allTrips.value = allTripsRes.data.data || [];
    featuredTrips.value = featuredRes.data.data || [];
    reviews.value = reviewsRes.data.data || [];
    
    // Update stats
    if (statsRes.data?.data) {
      const s = statsRes.data.data;
      statItems.value[0].target = s.total_customers;
      statItems.value[1].target = s.avg_rating;
      statItems.value[2].target = s.total_trips;
    }

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
    
    // Tawk.to logic
    if (!window.Tawk_API) {
      window.Tawk_API = window.Tawk_API || {};
      window.Tawk_LoadStart = new Date();
      (function(){
        var s1=document.createElement("script"),s0=document.getElementsByTagName("script")[0];
        s1.async=true;
        s1.src='https://embed.tawk.to/69e8c26faed59b1c34e3582a/1jmvrs6vj';
        s1.charset='UTF-8';
        s1.setAttribute('crossorigin','*');
        s0.parentNode.insertBefore(s1,s0);
      })();
    } else if (typeof window.Tawk_API.showWidget === 'function') {
      window.Tawk_API.showWidget();
    }
  } catch (e) {
    console.error('Failed to load home data', e);
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
/* Hero Slider */
.hero-slider {
  isolation: isolate;
  background: #0b1510;
}

.hero-slide {
  opacity: 0;
  transition: opacity 2.4s cubic-bezier(0.22, 1, 0.36, 1);
  z-index: 0;
  will-change: opacity;
}

.hero-slide-img {
  transform: scale(1.04) translate3d(0, 0, 0);
  filter: saturate(1.06) contrast(1.04) brightness(0.78);
  transition:
    transform 8.5s cubic-bezier(0.2, 0.6, 0.2, 1),
    filter 2.4s ease;
  will-change: transform, filter;
}

.hero-slide--active {
  opacity: 1;
  z-index: 1;
}

.hero-slide--active .hero-slide-img {
  transform: scale(1.14) translate3d(0, 0, 0);
  filter: saturate(1.12) contrast(1.08) brightness(0.9);
}

.hero-slider-glow {
  background:
    radial-gradient(80% 60% at 15% 20%, rgba(255, 255, 255, 0.14), transparent 60%),
    radial-gradient(70% 55% at 85% 30%, rgba(76, 175, 125, 0.18), transparent 62%);
  mix-blend-mode: screen;
  opacity: 0.45;
  animation: heroGlowShift 14s ease-in-out infinite alternate;
}

.hero-slider-vignette {
  background: radial-gradient(circle at center, transparent 35%, rgba(0, 0, 0, 0.32) 100%);
}

@keyframes heroGlowShift {
  from { transform: translate3d(-1.5%, -1%, 0) scale(1); }
  to   { transform: translate3d(1.5%, 1%, 0) scale(1.04); }
}

/* Hero animations */
.hero-content {
  animation: fadeUp 1s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
.search-bar {
  opacity: 0;
  animation: fadeUp 1s cubic-bezier(0.16, 1, 0.3, 1) 0.3s forwards;
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
  .hero-slider-glow,
  .hero-content,
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
