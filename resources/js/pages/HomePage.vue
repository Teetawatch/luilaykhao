<template>
  <div class="homepage bg-[var(--color-sand)] font-anuphan selection:bg-[var(--color-accent)] selection:text-white">

    <!-- ══════════════════════════════════════════
         HERO SECTION
    ══════════════════════════════════════════ -->
    <section class="relative h-[90vh] min-h-[700px] w-full flex items-center justify-center overflow-hidden -mt-16">
      <!-- Background with Parallax effect -->
      <div class="absolute inset-0 transform scale-105 transition-transform duration-[20s] ease-out hover:scale-110">
        <img
          src="/images/khaochangphueak.png"
          alt="ลุยเลเขา"
          class="w-full h-full object-cover"
        />
        <!-- Gradient Overlay for better text readability -->
        <div class="absolute inset-0 bg-gradient-to-b from-black/60 via-black/30 to-[var(--color-sand)]/90"></div>
      </div>

      <!-- Atmospheric orbs -->
      <div class="absolute top-1/4 left-1/4 w-96 h-96 bg-[var(--color-accent-light)]/20 rounded-full blur-[120px] pointer-events-none mix-blend-screen"></div>
      <div class="absolute bottom-1/3 right-1/4 w-[30rem] h-[30rem] bg-[var(--color-gold)]/15 rounded-full blur-[120px] pointer-events-none mix-blend-screen"></div>

      <!-- Content -->
      <div class="hero-content relative z-10 text-center px-4 max-w-6xl w-full pt-16 md:pt-24 pb-12">
        
        <!-- Headline -->
        <h1 class="font-anuphan text-white text-4xl md:text-5xl lg:text-[4rem] font-extrabold mb-6 leading-[1.3] tracking-tight drop-shadow-2xl">
          การเที่ยวที่ดี เริ่มจาก<br class="hidden md:block" />
          <span class="text-[var(--color-accent-light)]">ความรู้สึกที่ดี</span>
          ตั้งแต่ตอนจอง
        </h1>
        
        <!-- Subheadline -->
        <p class="text-white/80 text-lg md:text-xl mb-12 font-medium max-w-2xl mx-auto leading-relaxed drop-shadow-md">
          จองทริปดำน้ำ เดินป่า และรถตู้พรีเมียม ครบจบในที่เดียว<br class="hidden md:block" /> รวดเร็ว ปลอดภัย ประทับใจ ไม่ต้องรอ
        </p>

        <!-- Modern Floating Search Bar -->
        <div class="search-bar relative bg-white rounded-[1.5rem] md:rounded-[2rem] shadow-[0_20px_40px_-10px_rgba(0,0,0,0.2)] flex flex-col md:flex-row items-stretch md:items-center p-1.5 gap-1 max-w-4xl mx-auto border border-white/40 ring-1 ring-black/5 transform transition-all duration-500 hover:shadow-[0_30px_60px_-10px_rgba(0,0,0,0.3)] z-20">
          
          <!-- Destination -->
          <div class="flex items-center flex-1 w-full px-4 py-3 md:py-2.5 hover:bg-gray-50/80 rounded-[1.2rem] md:rounded-[1.5rem] transition-colors group cursor-pointer relative">
            <div class="w-10 h-10 md:w-11 md:h-11 rounded-full bg-[var(--color-primary)]/10 flex items-center justify-center mr-3 group-hover:bg-[var(--color-primary)]/20 transition-colors shadow-inner ring-1 ring-black/5 shrink-0">
              <span class="material-symbols-rounded text-[var(--color-primary)] text-[22px] md:text-[24px]">explore</span>
            </div>
            <div class="flex flex-col items-start min-w-0 flex-1">
              <label class="text-[10px] md:text-[11px] uppercase tracking-widest text-gray-500 font-bold mb-0.5">หมวดหมู่ทริป</label>
              <select
                v-model="searchCategory"
                class="bg-transparent border-none focus:ring-0 p-0 text-gray-900 font-extrabold w-full text-sm md:text-base outline-none appearance-none cursor-pointer pr-6"
              >
                <option value="">เลือกหมวดหมู่</option>
                <option value="snorkeling">ดำน้ำตื้น</option>
                <option value="trekking">เดินป่า</option>
                <option value="climbing">รถตู้พรีเมียม</option>
              </select>
              <span class="material-symbols-rounded text-[18px] absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none transition-transform group-hover:text-gray-600">expand_more</span>
            </div>
          </div>

          <div class="hidden md:block w-px h-12 bg-gray-100 shrink-0"></div>

          <!-- Date -->
          <div class="flex items-center flex-1 w-full px-4 py-3 md:py-2.5 hover:bg-gray-50/80 rounded-[1.2rem] md:rounded-[1.5rem] transition-colors group cursor-pointer">
            <div class="w-10 h-10 md:w-11 md:h-11 rounded-full bg-[var(--color-accent)]/10 flex items-center justify-center mr-3 group-hover:bg-[var(--color-accent)]/20 transition-colors shadow-inner ring-1 ring-black/5 shrink-0">
              <span class="material-symbols-rounded text-[var(--color-accent)] text-[22px] md:text-[24px]">calendar_today</span>
            </div>
            <div class="flex flex-col items-start min-w-0 flex-1">
              <label class="text-[10px] md:text-[11px] uppercase tracking-widest text-gray-500 font-bold mb-0.5">วันที่เดินทาง</label>
              <input
                v-model="searchDate"
                type="date"
                class="bg-transparent border-none focus:ring-0 p-0 text-gray-900 font-extrabold placeholder:text-gray-300 w-full text-sm md:text-base outline-none cursor-pointer"
              />
            </div>
          </div>

          <div class="hidden md:block w-px h-12 bg-gray-100 shrink-0"></div>

          <!-- Travelers -->
          <div class="flex items-center flex-1 w-full px-4 py-3 md:py-2.5 hover:bg-gray-50/80 rounded-[1.2rem] md:rounded-[1.5rem] transition-colors group cursor-pointer relative">
            <div class="w-10 h-10 md:w-11 md:h-11 rounded-full bg-[var(--color-primary)]/10 flex items-center justify-center mr-3 group-hover:bg-[var(--color-primary)]/20 transition-colors shadow-inner ring-1 ring-black/5 shrink-0">
              <span class="material-symbols-rounded text-[var(--color-primary)] text-[22px] md:text-[24px]">group</span>
            </div>
            <div class="flex flex-col items-start min-w-0 flex-1">
              <label class="text-[10px] md:text-[11px] uppercase tracking-widest text-gray-500 font-bold mb-0.5">ผู้เดินทาง</label>
              <select
                v-model="searchTravelers"
                class="bg-transparent border-none focus:ring-0 p-0 text-gray-900 font-extrabold w-full text-sm md:text-base outline-none appearance-none cursor-pointer pr-4"
              >
                <option value="1">1 ท่าน</option>
                <option value="2">2 ท่าน</option>
                <option value="3">3 ท่าน+</option>
              </select>
              <span class="material-symbols-rounded text-[18px] absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none transition-transform group-hover:text-gray-600 group-hover:translate-y-[-30%]">expand_more</span>
            </div>
          </div>

          <!-- Search Button -->
          <router-link
            :to="searchCategory ? `/trips?type=${searchCategory}` : '/trips'"
            class="bg-[var(--color-primary)] hover:bg-[var(--color-accent)] text-white px-6 py-4 md:py-3.5 rounded-[1.2rem] md:rounded-[1.5rem] font-bold transition-all duration-500 shadow-[0_8px_16px_rgba(45,122,79,0.25)] hover:shadow-[0_12px_24px_rgba(45,122,79,0.4)] hover:-translate-y-0.5 flex items-center justify-center gap-2 whitespace-nowrap shrink-0 cursor-pointer w-full md:w-auto mt-1 md:mt-0 md:ml-1"
          >
            <span class="material-symbols-rounded text-[24px]">search</span>
            <span class="text-lg md:text-base lg:text-lg pr-1">ค้นหาทริป</span>
          </router-link>
        </div>
        
        <!-- Popular Searches / Quick Tags -->
        <div class="mt-8 flex flex-wrap items-center justify-center gap-3 text-white/90 text-sm font-medium z-10 relative">
          <span class="mr-1 opacity-70">ทริปแนะนำ:</span>
          <template v-if="featuredTrips.length > 0">
            <router-link 
              v-for="trip in featuredTrips.slice(0, 3)" 
              :key="trip.id"
              :to="`/trips/${trip.slug}`" 
              class="flex items-center gap-1.5 px-4 py-1.5 rounded-full bg-white/10 hover:bg-[var(--color-accent-light)] border border-white/20 cursor-pointer transition-all hover:-translate-y-0.5 backdrop-blur-md shadow-lg"
            >
              <span class="material-symbols-rounded text-[16px]">{{ typeFeaturedIcon(trip.type) }}</span>
              {{ trip.title.split(' ').slice(0, 2).join(' ') }}
            </router-link>
          </template>
          <template v-else>
            <router-link to="/trips?type=snorkeling" class="flex items-center gap-1.5 px-4 py-1.5 rounded-full bg-white/10 hover:bg-white/25 border border-white/20 cursor-pointer transition-all hover:-translate-y-0.5 backdrop-blur-md shadow-lg">
              <span class="material-symbols-rounded text-[16px]">scuba_diving</span> ดำน้ำตื้น
            </router-link>
            <router-link to="/trips?type=trekking" class="flex items-center gap-1.5 px-4 py-1.5 rounded-full bg-white/10 hover:bg-white/25 border border-white/20 cursor-pointer transition-all hover:-translate-y-0.5 backdrop-blur-md shadow-lg">
              <span class="material-symbols-rounded text-[16px]">hiking</span> เดินป่า
            </router-link>
          </template>
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
         SOCIAL PROOF BAR
    ══════════════════════════════════════════ -->
    <section class="bg-[var(--color-sand)] relative z-10 pb-16 pt-8">
      <div class="max-w-7xl mx-auto px-6 md:px-8">
        <div class="bg-white rounded-[2rem] shadow-[0_10px_40px_rgba(0,0,0,0.05)] border border-gray-100 p-8 md:p-10 -mt-16 relative z-20">
          <div class="grid grid-cols-2 md:grid-cols-4 gap-8 md:gap-4 divide-x-0 md:divide-x divide-gray-100">
            <div v-for="(stat, index) in statItems" :key="stat.label" class="flex flex-col items-center justify-center text-center px-4 group">
              <div class="w-14 h-14 rounded-2xl bg-[var(--color-sand)] flex items-center justify-center text-[var(--color-accent)] mb-4 group-hover:scale-110 group-hover:bg-[var(--color-accent)] group-hover:text-white transition-all duration-300">
                <span class="material-symbols-rounded text-[28px]">{{ stat.icon }}</span>
              </div>
              <div class="text-3xl font-extrabold text-[var(--color-text-dark)] mb-1">{{ stat.value }}</div>
              <div class="text-sm font-medium text-[var(--color-text-muted)]">{{ stat.label }}</div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         CATEGORIES SECTION
    ══════════════════════════════════════════ -->
    <section class="py-20 bg-[var(--color-sand)] relative overflow-hidden">
      <!-- Decorative background text -->
      <div class="absolute top-10 left-0 text-[12rem] font-black text-gray-200/40 select-none pointer-events-none whitespace-nowrap z-0 tracking-tighter">
        ลุยเลเขา
      </div>
      
      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="flex flex-col md:flex-row md:items-end justify-between mb-12 gap-6">
          <div class="max-w-2xl">
            <span class="text-[var(--color-accent)] font-bold tracking-wider uppercase text-sm mb-2 block">หมวดหมู่การเดินทาง</span>
            <h2 class="font-anuphan text-4xl md:text-5xl font-extrabold text-[var(--color-text-dark)] tracking-tight">เลือกประสบการณ์<br />ในแบบของคุณ</h2>
          </div>
          <p class="text-[var(--color-text-muted)] text-base max-w-sm md:text-right font-medium">
            ค้นพบการเดินทางที่ออกแบบมาเพื่อคุณ ไม่ว่าจะเป็นการผจญภัยหรือการพักผ่อน
          </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
          <router-link
            v-for="cat in categories"
            :key="cat.type"
            :to="`/trips?type=${cat.type}`"
            class="group relative bg-white rounded-[2.5rem] overflow-hidden shadow-[0_10px_30px_rgba(0,0,0,0.03)] hover:shadow-[0_40px_60px_rgba(0,0,0,0.12)] transition-all duration-700 hover:-translate-y-3 border border-gray-100 block isolate h-[450px]"
          >
            <!-- Background Image with Zoom Effect -->
            <div class="absolute inset-0 z-[-1] overflow-hidden">
              <img 
                :src="cat.image" 
                :alt="cat.label"
                class="w-full h-full object-cover transition-transform duration-1000 group-hover:scale-110"
              />
              <!-- Premium Overlays -->
              <div 
                class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/40 to-black/10 transition-opacity duration-500 opacity-80 group-hover:opacity-90"
              ></div>
              <div 
                class="absolute inset-0 mix-blend-overlay opacity-30 group-hover:opacity-50 transition-opacity duration-500"
                :style="`background: ${cat.color}`"
              ></div>
            </div>
            
            <div class="p-8 md:p-10 flex flex-col h-full relative z-10">
              <!-- Animated Badge -->
              <div class="w-14 h-14 rounded-2xl flex items-center justify-center transition-all duration-500 shadow-xl group-hover:shadow-[0_0_20px_rgba(255,255,255,0.3)] mb-auto backdrop-blur-md border border-white/20" :style="`background: ${cat.bgColor}; color: ${cat.color}`">
                <span class="material-symbols-rounded text-[28px] transform group-hover:rotate-[360deg] transition-transform duration-1000">{{ cat.icon }}</span>
              </div>
              
              <div class="mt-auto transform translate-y-4 group-hover:translate-y-0 transition-transform duration-500">
                <h3 class="text-3xl font-black text-white tracking-tight mb-3 transition-colors drop-shadow-md">
                  {{ cat.label }}
                </h3>
                <p class="text-white/80 text-base font-medium mb-8 leading-relaxed opacity-0 group-hover:opacity-100 transition-all duration-500 delay-100">
                  {{ cat.desc }}
                </p>
                
                <div class="flex items-center text-sm font-black text-white uppercase tracking-widest bg-white/10 backdrop-blur-md w-fit px-6 py-3 rounded-full border border-white/20 shadow-lg hover:bg-white hover:text-[var(--color-text-dark)] transition-all duration-300">
                  <span class="mr-3">ดูทริปทั้งหมด</span>
                  <span class="material-symbols-rounded text-[20px] transform group-hover:translate-x-2 transition-transform duration-300">arrow_right_alt</span>
                </div>
              </div>
            </div>
            
            <!-- Side subtle border -->
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
          src="/images/experience_overlay.png" 
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
                v-if="trip.cover_image"
                :src="trip.cover_image"
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
                @click.prevent="wishlistStore.toggleFavorite(trip.id)"
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
                <span v-else class="text-gray-400 text-xs font-medium italic">ยังไม่มีรีวิว</span>
              </div>
              
              <h4 class="text-[1.1rem] font-extrabold text-[var(--color-text-dark)] mb-2 group-hover:text-[var(--color-accent)] transition-colors duration-300 leading-snug line-clamp-2">
                {{ trip.title }}
              </h4>
              
              <div class="mt-auto pt-4 flex justify-between items-end border-t border-gray-100">
                <div class="flex flex-col">
                  <span class="text-xs text-[var(--color-text-muted)] font-bold uppercase tracking-wider mb-0.5">เริ่มต้น</span>
                  <div class="flex items-baseline gap-1">
                    <span class="text-xl font-black text-[var(--color-text-dark)]">฿{{ Number(trip.price_per_person).toLocaleString() }}</span>
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
          src="/images/recommend_overlay.png" 
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

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-stretch">
          <!-- Large Featured Card (first trip) -->
          <router-link
            :to="`/trips/${featuredTrips[0].slug}`"
            class="lg:col-span-8 group relative overflow-hidden rounded-[2rem] shadow-2xl h-[500px] lg:h-[600px] cursor-pointer block isolate"
          >
            <img
              :src="featuredTrips[0].cover_image || '/images/placeholder.jpg'"
              :alt="featuredTrips[0].title"
              class="absolute inset-0 w-full h-full object-cover transition-transform duration-1000 group-hover:scale-105"
            />
            <div class="absolute inset-0 bg-gradient-to-t from-[var(--color-primary)] via-[var(--color-primary)]/40 to-transparent opacity-90"></div>
            
            <div class="absolute inset-0 p-8 md:p-12 flex flex-col justify-end">
              <div class="flex flex-wrap items-center gap-3 mb-6">
                <span class="bg-[var(--color-accent-light)] text-white px-4 py-1.5 rounded-full text-xs font-black uppercase tracking-widest shadow-lg">{{ typeLabel(featuredTrips[0].type) }}</span>
                <span class="bg-white/20 backdrop-blur-md text-white px-4 py-1.5 rounded-full text-xs font-bold shadow-lg flex items-center gap-1">
                  <span class="material-symbols-rounded text-[14px] text-[#FFB020]" style="font-variation-settings:'FILL' 1">star</span>
                  {{ Number(featuredTrips[0].rating || 0).toFixed(1) }}
                </span>
              </div>
              
              <h3 class="font-anuphan text-white text-3xl md:text-5xl font-extrabold mb-4 leading-tight group-hover:text-[var(--color-accent-light)] transition-colors">{{ featuredTrips[0].title }}</h3>
              <p class="text-white/80 max-w-xl mb-8 leading-relaxed text-base md:text-lg font-medium line-clamp-2">{{ featuredTrips[0].description || featuredTrips[0].location }}</p>
              
              <div class="flex items-center justify-between flex-wrap gap-4 mt-auto">
                <div class="flex flex-col">
                  <span class="text-white/60 text-sm font-bold uppercase tracking-wider mb-1">ราคาเริ่มต้น</span>
                  <span class="text-white text-3xl font-black">฿{{ Number(featuredTrips[0].price_per_person).toLocaleString('th-TH') }}</span>
                </div>
                <div class="bg-white text-[var(--color-text-dark)] group-hover:bg-[var(--color-accent-light)] group-hover:text-white px-8 py-4 rounded-full font-extrabold shadow-xl transition-all duration-300 flex items-center gap-2">
                  ดูรายละเอียด
                  <span class="material-symbols-rounded text-[20px] group-hover:translate-x-1 transition-transform">arrow_forward</span>
                </div>
              </div>
            </div>
          </router-link>

          <!-- Side Cards (trip 2 & 3) -->
          <div class="lg:col-span-4 flex flex-col gap-6">
            <router-link
              v-for="(trip, idx) in featuredTrips.slice(1, 3)"
              :key="trip.id"
              :to="`/trips/${trip.slug}`"
              class="flex-1 group relative overflow-hidden rounded-[2rem] bg-gray-900 cursor-pointer block p-6 md:p-8 flex flex-col justify-between hover:shadow-2xl transition-all duration-500 transform hover:-translate-y-2 isolate"
            >
              <!-- Background Image -->
              <img
                :src="trip.cover_image || '/images/placeholder.jpg'"
                :alt="trip.title"
                class="absolute inset-0 w-full h-full object-cover transition-transform duration-[1.5s] group-hover:scale-110 opacity-60"
              />
              <!-- Premium Overlay -->
              <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/40 to-black/20 group-hover:via-black/50 transition-all duration-500"></div>
              
              <div class="relative z-10">
                <div class="flex justify-between items-start mb-6">
                  <span
                    class="px-3 py-1.5 rounded-full text-[10px] font-black uppercase tracking-wider backdrop-blur-md border border-white/20"
                    :class="idx === 0 ? 'bg-[var(--color-accent)] text-white' : 'bg-[var(--color-gold)] text-white'"
                  >{{ typeLabel(trip.type) }}</span>
                  <div class="w-10 h-10 rounded-full flex items-center justify-center backdrop-blur-md border border-white/20 bg-white/10 text-white">
                    <span class="material-symbols-rounded text-[20px]">{{ typeFeaturedIcon(trip.type) }}</span>
                  </div>
                </div>
                <h4 class="text-xl font-extrabold text-white mb-2 group-hover:text-[var(--color-accent-light)] transition-colors leading-snug drop-shadow-md">{{ trip.title }}</h4>
                <p class="text-white/70 text-sm font-medium line-clamp-2 leading-relaxed">{{ trip.description || trip.location }}</p>
              </div>
              
              <div class="relative z-10 pt-6 mt-6 border-t border-white/10 flex justify-between items-end">
                <div class="flex flex-col">
                  <span class="text-[10px] text-white/50 font-bold uppercase tracking-wider mb-0.5">เริ่มต้น</span>
                  <span class="text-xl font-black text-white">฿{{ Number(trip.price_per_person).toLocaleString('th-TH') }}</span>
                </div>
                <div class="w-10 h-10 rounded-full bg-white/10 backdrop-blur-md border border-white/20 flex items-center justify-center group-hover:bg-white group-hover:text-[var(--color-text-dark)] transition-all duration-500">
                  <span class="material-symbols-rounded text-[20px]">arrow_outward</span>
                </div>
              </div>
            </router-link>

            <!-- Placeholder card when only 1 featured trip exists -->
            <router-link
              v-if="featuredTrips.length === 1"
              to="/trips"
              class="flex-1 group cursor-pointer bg-white/5 backdrop-blur-sm rounded-[2rem] p-6 flex flex-col items-center justify-center hover:bg-white/10 transition-all duration-300 border-2 border-dashed border-white/20 text-center gap-4"
            >
              <div class="w-16 h-16 rounded-full bg-white/10 flex items-center justify-center text-white group-hover:scale-110 transition-transform">
                <span class="material-symbols-rounded text-[32px]">explore</span>
              </div>
              <p class="text-white font-bold tracking-wide">ดูทริปแนะนำทั้งหมด</p>
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
    <section class="py-24 bg-white border-t border-gray-100">
      <div class="max-w-7xl mx-auto px-6 md:px-8">
        <div class="text-center mb-16">
          <h2 class="font-anuphan text-4xl font-extrabold text-[var(--color-text-dark)] tracking-tight">ทำไมต้องเลือก ลุยเลเขา?</h2>
        </div>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-8">
          <div
            v-for="trust in trustItems"
            :key="trust.title"
            class="flex flex-col items-center text-center group"
          >
            <div 
              class="mb-6 transition-all duration-300 flex items-center justify-center"
              :class="trust.image ? 'w-42 h-42' : 'w-20 h-20 rounded-[1.5rem] bg-[var(--color-sand)] text-[var(--color-accent)] group-hover:bg-[var(--color-accent)] group-hover:text-white shadow-sm group-hover:shadow-xl group-hover:-translate-y-2'"
            >
              <img 
                v-if="trust.image" 
                :src="trust.image" 
                :alt="trust.title" 
                class="w-full h-full object-contain group-hover:scale-110 group-hover:-translate-y-2 transition-all duration-300" 
              />
              <span v-else class="material-symbols-rounded text-[36px]">{{ trust.icon }}</span>
            </div>
            <h5 class="font-extrabold text-xl text-[var(--color-text-dark)] mb-3">{{ trust.title }}</h5>
            <p class="text-[var(--color-text-muted)] text-base font-medium leading-relaxed max-w-[250px]">{{ trust.desc }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         NEWSLETTER CTA SECTION (Full-Width Redesign)
    ══════════════════════════════════════════ -->
    <section class="relative py-32 w-full overflow-hidden bg-[var(--color-primary)]">
      <!-- Sophisticated Background Elements -->
      <div class="absolute inset-0 z-0 bg-[url('https://www.transparenttextures.com/patterns/carbon-fibre.png')] opacity-10"></div>
      <div class="absolute top-0 right-0 w-[50rem] h-[50rem] bg-[var(--color-accent-light)]/10 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/4 pointer-events-none"></div>
      <div class="absolute bottom-0 left-0 w-[40rem] h-[40rem] bg-[var(--color-gold)]/10 rounded-full blur-[120px] translate-y-1/2 -translate-x-1/4 pointer-events-none"></div>
      
      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="flex flex-col lg:flex-row items-center justify-between gap-12 lg:gap-24">
          
          <!-- Text Content -->
          <div class="flex-1 text-center lg:text-left max-w-2xl">
            <h2 class="font-anuphan text-4xl md:text-5xl lg:text-7xl font-black text-white mb-8 leading-[1.05] tracking-tight">
              รับดีลเกาะลับก่อนใคร<br />
              <span class="text-[var(--color-accent-light)]">รับส่วนลด 10%</span> ทันที
            </h2>
            <p class="text-white/70 text-lg md:text-xl font-medium leading-relaxed mb-0 max-w-xl">
              ร่วมเป็นสมาชิกครอบครัวนักเดินทางกับหน้าใหม่ของ "ลุยเลเขา" รับข่าวสารทริปคัดสรรพิเศษและส่วนลดสมาชิกที่คุณจะหาไม่ได้จากที่อื่น
            </p>
          </div>

          <!-- Integrated Form (No "box" frame) -->
          <div class="flex-1 w-full max-w-xl">
            <div class="relative group">
              <!-- Subtle glow background -->
              <div class="absolute -inset-1 bg-gradient-to-r from-[var(--color-accent-light)] to-[var(--color-gold)] rounded-[2.5rem] blur opacity-20 group-hover:opacity-40 transition duration-1000"></div>
              
              <div class="relative bg-white/5 backdrop-blur-2xl p-10 md:p-12 rounded-[2.5rem] border border-white/10 shadow-3xl">
                <form @submit.prevent class="space-y-6">
                  <div class="relative">
                    <label class="block text-white/50 text-[10px] items-center uppercase tracking-widest font-black mb-3 ml-1">Work Email Address</label>
                    <div class="relative">
                      <div class="absolute inset-y-0 left-0 pl-6 flex items-center pointer-events-none">
                        <span class="material-symbols-rounded text-[var(--color-accent-light)] group-hover:scale-110 transition-transform">alternate_email</span>
                      </div>
                      <input
                        type="email"
                        placeholder="your@email.com"
                        class="w-full bg-white/10 border border-white/20 rounded-2xl py-5 pl-16 pr-8 text-white placeholder:text-white/30 focus:ring-2 focus:ring-[var(--color-accent-light)] focus:border-transparent outline-none text-lg font-bold transition-all duration-500 shadow-inner"
                      />
                    </div>
                  </div>
                  
                  <button
                    type="submit"
                    class="w-full bg-white text-[var(--color-primary)] hover:bg-[var(--color-accent-light)] hover:text-white py-5 rounded-2xl font-black text-xl transition-all duration-500 shadow-xl hover:shadow-[0_20px_40px_rgba(255,255,255,0.15)] flex items-center justify-center gap-3 cursor-pointer group/btn overflow-hidden relative isolate"
                  >
                    <span class="relative z-10">สมัครสมาชิกฟรี</span>
                    <span class="material-symbols-rounded relative z-10 text-2xl transform group-hover/btn:translate-x-2 transition-transform duration-500">arrow_right_alt</span>
                  </button>
                </form>
                
                <div class="mt-8 flex items-center justify-center gap-6 opacity-40">
                  <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-white text-[16px]">verified</span>
                    <span class="text-white text-[10px] font-bold uppercase tracking-widest">No Spam</span>
                  </div>
                  <div class="w-px h-3 bg-white"></div>
                  <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-white text-[16px]">lock_open</span>
                    <span class="text-white text-[10px] font-bold uppercase tracking-widest">Secure</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </section>

  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../lib/axios';
import { useWishlistStore } from '../stores/wishlist';

const wishlistStore = useWishlistStore();

const trips = ref([]);
const featuredTrips = ref([]);
const loading = ref(true);
const searchDestination = ref('');
const searchCategory = ref('');
const searchDate = ref('');
const searchTravelers = ref('1');

const statItems = ref([
  { icon: 'groups', value: '1,200+', label: 'นักเดินทางที่ไว้ใจเรา' },
  { icon: 'star', value: '5.0', label: 'คะแนนความพึงพอใจ' },
  { icon: 'map', value: '10+', label: 'เส้นทางท่องเที่ยว' },
  { icon: 'verified_user', value: '100%', label: 'รับประกันความปลอดภัย' },
]);

const categories = [
  {
    type: 'snorkeling',
    label: 'ดำน้ำตื้น',
    desc: 'สัมผัสความงามของโลกใต้ทะเล พร้อมทีมงานมืออาชีพ',
    image: '/images/diving_show.png',
    icon: 'scuba_diving',
    color: '#3B9DD4',
    bgColor: '#E8F4FA',
  },
  {
    type: 'trekking',
    label: 'เดินป่า',
    desc: 'ผจญภัยในเส้นทางธรรมชาติที่ท้าทาย สูดอากาศบริสุทธิ์ให้เต็มปอด',
    image: '/images/hiking_show.png',
    icon: 'hiking',
    color: '#2D7A4F',
    bgColor: '#E8F5EC',
  },
  {
    type: 'climbing',
    label: 'รถตู้พรีเมียม',
    desc: 'เดินทางอย่างสะดวกสบายและปลอดภัย ด้วยบริการรถตู้ระดับพรีเมียม',
    image: '/images/van_show.png',
    icon: 'airport_shuttle',
    color: '#C8963E',
    bgColor: '#FFF8EE',
  },
];

const reviews = ref([]);

const trustItems = [
  {
    image: '/images/travel_safety.png',
    title: 'ความปลอดภัยสูงสุด',
    desc: 'อุปกรณ์ผ่านการตรวจสอบมาตรฐานสากลทุกครั้งก่อนใช้งาน',
  },
  {
    image: '/images/247_support.png',
    title: 'บริการ 24 ชั่วโมง',
    desc: 'ทีมงานพร้อมดูแลและให้ความช่วยเหลือคุณตลอดการเดินทาง',
  },
  {
    image: '/images/nature_travel.png',
    title: 'ท่องเที่ยวอย่างยั่งยืน',
    desc: 'เราให้ความสำคัญกับการอนุรักษ์ธรรมชาติในทุกทริปการเดินทาง',
  },
  {
    icon: 'payments',
    title: 'โปร่งใส ราคายุติธรรม',
    desc: 'ไม่มีค่าใช้จ่ายแอบแฝง คุ้มค่ากับบริการระดับพรีเมียมที่คุณได้รับ',
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

onMounted(async () => {
  try {
    const [tripsRes, featuredRes, reviewsRes, statsRes] = await Promise.all([
      api.get('/trips', { params: { per_page: 8 } }),
      api.get('/trips/featured'),
      api.get('/reviews', { params: { per_page: 3 } }),
      api.get('/stats'),
    ]);
    trips.value = tripsRes.data.data;
    featuredTrips.value = featuredRes.data.data || [];
    reviews.value = reviewsRes.data.data || [];
    
    // Update stats
    if (statsRes.data?.data) {
      const s = statsRes.data.data;
      statItems.value = [
        { icon: 'groups', value: `${s.total_customers.toLocaleString()}+`, label: 'นักเดินทางที่ไว้ใจเรา' },
        { icon: 'star', value: s.avg_rating.toString(), label: 'คะแนนความพึงพอใจ' },
        { icon: 'map', value: `${s.total_trips}+`, label: 'เส้นทางท่องเที่ยว' },
        { icon: 'verified_user', value: '100%', label: 'รับประกันความปลอดภัย' },
      ];
    }
  } catch (e) {
    console.error('Failed to load home data', e);
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
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
