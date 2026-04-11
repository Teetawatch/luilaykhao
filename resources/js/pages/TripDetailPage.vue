<template>
  <div class="trip-detail-page bg-[var(--color-sand)] min-h-screen font-anuphan selection:bg-[var(--color-accent)] selection:text-white pb-20">
    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center min-h-[70vh]">
      <div class="flex flex-col items-center gap-5">
        <div class="w-16 h-16 border-4 border-gray-200 border-t-[var(--color-accent)] rounded-full animate-spin"></div>
        <span class="text-[var(--color-text-dark)] font-bold text-lg tracking-wide">กำลังเตรียมข้อมูลทริป...</span>
      </div>
    </div>

    <div v-else-if="trip" class="animate-fade-in">
      <!-- Hero Section -->
      <section class="relative h-[60vh] min-h-[500px] md:h-[70vh] w-full overflow-hidden -mt-16">
        <img
          v-if="trip.cover_image"
          :src="trip.cover_image"
          :alt="trip.title"
          class="w-full h-full object-cover transform scale-105 transition-transform duration-[20s] hover:scale-110"
          @error="(e) => e.target.style.display='none'"
        />
        <div v-else class="w-full h-full bg-[var(--color-primary)]"></div>

        <!-- Solid Overlay for text readability -->
        <div class="absolute inset-0 bg-black/40"></div>

        <!-- Hero Content -->
        <div class="absolute inset-0 flex items-end p-6 md:p-12 lg:p-16 max-w-screen-2xl mx-auto z-10">
          <div class="max-w-4xl hero-content w-full">
            <div class="flex flex-wrap items-center gap-3 mb-6">
              <span
                class="px-5 py-2 rounded-full text-sm font-extrabold uppercase tracking-widest shadow-lg text-white"
                :class="typeBadgeClass"
              >
                {{ typeLabel }}
              </span>
              <span class="bg-white/20 backdrop-blur-md text-white px-5 py-2 rounded-full text-sm font-bold shadow-lg flex items-center gap-1.5 border border-white/10">
                <span class="material-symbols-rounded text-[16px] text-[#FFB020]" style="font-variation-settings:'FILL' 1">star</span>
                {{ Number(trip.rating || 0).toFixed(1) }} ({{ trip.review_count || 0 }} รีวิว)
              </span>
              <span v-if="trip.is_women_only" class="bg-pink-500/80 backdrop-blur-md text-white px-5 py-2 rounded-full text-sm font-black shadow-lg flex items-center gap-1.5 border border-pink-400/30 animate-pulse">
                <span class="material-symbols-rounded text-[18px]">female</span>
                ทริปสำหรับผู้หญิงเท่านั้น
              </span>
            </div>
            
            <h1 class="text-white text-4xl md:text-5xl lg:text-7xl font-extrabold mb-6 leading-tight drop-shadow-xl tracking-tight">
              {{ trip.title }}
            </h1>
            
            <div class="flex flex-wrap items-center text-white gap-4 md:gap-6 text-sm md:text-base font-medium">
              <div class="flex items-center gap-2 bg-black/30 backdrop-blur-md px-4 py-2 rounded-full border border-white/10">
                <span class="material-symbols-rounded text-[18px]">location_on</span>
                <span>{{ trip.location }}</span>
              </div>
              <div class="flex items-center gap-2 bg-black/30 backdrop-blur-md px-4 py-2 rounded-full border border-white/10">
                <span class="material-symbols-rounded text-[18px]">schedule</span>
                <span>{{ trip.duration_days }} วัน</span>
              </div>
              <div class="flex items-center gap-2 bg-black/30 backdrop-blur-md px-4 py-2 rounded-full border border-white/10">
                <span class="material-symbols-rounded text-[18px]">terrain</span>
                <span>{{ diffLabel }}</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Content Grid -->
      <div class="max-w-screen-2xl mx-auto px-4 sm:px-6 md:px-8 py-16">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14">

          <!-- Left Column: Details -->
          <div class="lg:col-span-8 space-y-16">

            <!-- Gallery Bento Grid -->
            <section v-if="trip.gallery && trip.gallery.length > 0" class="gallery-section">
              <div class="grid grid-cols-4 grid-rows-2 gap-4 h-[400px] md:h-[500px]">
                <div class="col-span-2 row-span-2 overflow-hidden rounded-[2rem] group relative">
                  <img :src="trip.gallery[0]" :alt="trip.title" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110" />
                  <div class="absolute inset-0 bg-black/10 group-hover:bg-transparent transition-colors duration-300"></div>
                </div>
                <div v-if="trip.gallery[1]" class="col-span-2 row-span-1 overflow-hidden rounded-[1.5rem] group relative">
                  <img :src="trip.gallery[1]" :alt="trip.title" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110" />
                  <div class="absolute inset-0 bg-black/10 group-hover:bg-transparent transition-colors duration-300"></div>
                </div>
                <div v-if="trip.gallery[2]" class="col-span-1 row-span-1 overflow-hidden rounded-[1.5rem] group relative">
                  <img :src="trip.gallery[2]" :alt="trip.title" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110" />
                  <div class="absolute inset-0 bg-black/10 group-hover:bg-transparent transition-colors duration-300"></div>
                </div>
                <div v-if="trip.gallery[3]" class="col-span-1 row-span-1 overflow-hidden rounded-[1.5rem] relative group cursor-pointer">
                  <img :src="trip.gallery[3]" :alt="trip.title" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110" />
                  <div v-if="trip.gallery.length > 4" class="absolute inset-0 bg-black/50 flex items-center justify-center text-white backdrop-blur-sm transition-colors duration-300 group-hover:bg-black/60">
                    <div class="text-center">
                      <span class="material-symbols-rounded text-3xl mb-1">photo_library</span>
                      <div class="font-extrabold text-lg">+{{ trip.gallery.length - 4 }}</div>
                    </div>
                  </div>
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

            <!-- Story-telling Description -->
            <section class="description-section bg-white p-8 md:p-12 rounded-[2rem] border border-gray-100 shadow-[0_10px_40px_rgba(0,0,0,0.03)]">
              <h2 class="text-3xl md:text-4xl font-extrabold text-[var(--color-text-dark)] mb-6 tracking-tight">เกี่ยวกับทริปนี้</h2>
              <p class="text-[var(--color-text-mid)] leading-relaxed text-base md:text-lg whitespace-pre-line font-medium">{{ trip.description }}</p>
            </section>

            <!-- Highlights -->
            <section>
              <h3 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] mb-8 tracking-tight">จุดเด่นของทริป</h3>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div v-for="(hi, idx) in highlights" :key="idx" class="bg-white p-6 rounded-[1.5rem] border border-gray-100 shadow-[0_10px_30px_rgba(0,0,0,0.03)] hover:-translate-y-1 transition-transform duration-300 group flex gap-5 items-start">
                  <div class="w-14 h-14 rounded-2xl bg-[var(--color-sand)] group-hover:bg-[var(--color-accent)] transition-colors duration-300 flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-[28px] text-[var(--color-accent)] group-hover:text-white transition-colors duration-300">{{ hi.icon || 'star' }}</span>
                  </div>
                  <div>
                    <h4 class="text-lg font-extrabold text-[var(--color-text-dark)] mb-2">{{ hi.title }}</h4>
                    <p class="text-sm text-[var(--color-text-muted)] font-medium leading-relaxed">{{ hi.desc }}</p>
                  </div>
                </div>
              </div>
            </section>

            <!-- Inclusions / Exclusions -->
            <section class="grid grid-cols-1 md:grid-cols-2 gap-8">
              <div class="p-8 md:p-10 bg-white rounded-[2rem] border border-[#E8F5EC] shadow-[0_10px_40px_rgba(45,122,79,0.05)]">
                <h4 class="text-xl font-extrabold mb-6 flex items-center gap-3 text-[#2D7A4F]">
                  <span class="material-symbols-rounded text-[28px]" style="font-variation-settings:'FILL' 1">check_circle</span>
                  สิ่งที่รวมในทริป
                </h4>
                <ul class="space-y-4 text-base font-medium text-[var(--color-text-dark)]">
                  <li v-for="(item, i) in trip.inclusions" :key="i" class="flex items-start gap-3">
                    <span class="material-symbols-rounded text-[#2D7A4F] shrink-0 mt-0.5 text-[20px]">check</span>
                    <span>{{ item }}</span>
                  </li>
                </ul>
                <p v-if="!trip.inclusions?.length" class="text-sm text-gray-400 italic">ไม่ได้ระบุสิ่งที่รวมในทริป</p>
              </div>
              <div class="p-8 md:p-10 bg-white rounded-[2rem] border border-red-50 shadow-[0_10px_40px_rgba(239,68,68,0.05)]">
                <h4 class="text-xl font-extrabold mb-6 flex items-center gap-3 text-red-500">
                  <span class="material-symbols-rounded text-[28px]" style="font-variation-settings:'FILL' 1">cancel</span>
                  สิ่งที่ไม่รวม
                </h4>
                <ul class="space-y-4 text-base font-medium text-[var(--color-text-dark)]">
                  <li v-for="(item, i) in trip.exclusions" :key="i" class="flex items-start gap-3">
                    <span class="material-symbols-rounded text-red-400 shrink-0 mt-0.5 text-[20px]">close</span>
                    <span>{{ item }}</span>
                  </li>
                </ul>
                <p v-if="!trip.exclusions?.length" class="text-sm text-gray-400 italic">ไม่ได้ระบุสิ่งที่ไม่รวมในทริป</p>
              </div>
            </section>

            <!-- Trekking: Pickup Regions Info Section -->
            <section v-if="isTrekking && allPickupPoints.length">
              <h3 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] mb-3 tracking-tight">จุดขึ้นรถและราคาตามภูมิภาค</h3>
              <div class="flex items-center justify-between mb-8 flex-wrap gap-3">
                <p class="text-[var(--color-text-muted)] text-base font-medium">เลือกจุดขึ้นรถที่สะดวกสำหรับคุณ ราคาอาจแตกต่างกันในแต่ละพื้นที่</p>
                <button
                  @click="calculateDistances"
                  :disabled="distanceLoading"
                  class="inline-flex items-center gap-2 text-sm text-white bg-[var(--color-primary)] hover:bg-[var(--color-accent)] px-5 py-2.5 rounded-full font-bold transition-all duration-300 shadow-md hover:-translate-y-0.5 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <span v-if="distanceLoading" class="material-symbols-rounded text-[16px] animate-spin">progress_activity</span>
                  <span v-else class="material-symbols-rounded text-[16px]">near_me</span>
                  {{ distanceLoading ? 'กำลังคำนวณ...' : (distanceData.length ? 'คำนวณใหม่' : 'คำนวณระยะทางจากตำแหน่งของฉัน') }}
                </button>
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                <div
                  v-for="pt in allPickupPoints" :key="pt.id"
                  class="bg-white rounded-[1.5rem] border border-gray-100 overflow-hidden shadow-[0_10px_30px_rgba(0,0,0,0.03)] hover:shadow-[0_15px_40px_rgba(0,0,0,0.06)] transition-all duration-300 hover:-translate-y-1"
                >
                  <div class="flex items-center justify-between px-6 py-4 bg-[var(--color-sand)] border-b border-gray-100">
                    <span class="font-extrabold text-[var(--color-text-dark)] text-base flex items-center gap-2">
                      <span class="material-symbols-rounded text-[var(--color-accent)] text-[18px]">location_on</span>
                      {{ pt.region_label }}
                    </span>
                    <span class="font-black text-[var(--color-accent)] text-lg">฿{{ Number(pt.price).toLocaleString() }}</span>
                  </div>
                  <div class="px-6 py-5 space-y-3">
                    <p class="text-sm text-[var(--color-text-dark)] font-bold flex items-start gap-2.5">
                      <span class="material-symbols-rounded text-red-400 shrink-0 mt-0.5 text-[18px]">pin_drop</span>
                      {{ pt.pickup_location }}
                    </p>
                    <p v-if="pt.notes" class="text-sm text-[var(--color-text-muted)] font-medium flex items-start gap-2.5">
                      <span class="material-symbols-rounded text-[#FFB020] shrink-0 mt-0.5 text-[18px]">schedule</span>
                      {{ pt.notes }}
                    </p>
                    <!-- Distance info -->
                    <div v-if="getDistanceForPickup(pt.id)" class="flex flex-wrap gap-3 mt-2">
                      <span class="inline-flex items-center gap-1.5 text-sm bg-blue-50 text-blue-700 px-3 py-1.5 rounded-lg font-bold">
                        <span class="material-symbols-rounded text-[16px]">straighten</span>
                        {{ getDistanceForPickup(pt.id).distance?.text }}
                      </span>
                      <span class="inline-flex items-center gap-1.5 text-sm bg-green-50 text-green-700 px-3 py-1.5 rounded-lg font-bold">
                        <span class="material-symbols-rounded text-[16px]">schedule</span>
                        {{ getDistanceForPickup(pt.id).duration?.text }}
                      </span>
                    </div>
                    <div class="flex flex-wrap gap-2 mt-2">
                      <a v-if="pt.map_url" :href="pt.map_url" target="_blank"
                        class="inline-flex items-center gap-1.5 text-sm text-white bg-[var(--color-primary)] hover:bg-[var(--color-accent)] px-4 py-2 rounded-lg font-bold transition-colors">
                        <span class="material-symbols-rounded text-[16px]">map</span>
                        ดูแผนที่จุดรับ
                      </a>
                    </div>
                  </div>
                </div>
              </div>
            </section>

            <!-- Departure Point / Map area -->
            <section v-if="trip.departure_point || (trip.latitude && trip.longitude)">
              <h3 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] mb-8 tracking-tight">จุดนัดหมาย</h3>

              <!-- Google Maps embed -->
              <div v-if="trip.latitude && trip.longitude" class="rounded-[2rem] overflow-hidden border border-gray-100 shadow-[0_10px_40px_rgba(0,0,0,0.05)] bg-white">
                <iframe
                  :src="`https://www.google.com/maps?q=${trip.latitude},${trip.longitude}&z=14&output=embed`"
                  width="100%"
                  height="400"
                  style="border:0; display:block;"
                  allowfullscreen
                  loading="lazy"
                  referrerpolicy="no-referrer-when-downgrade"
                ></iframe>
                <div class="bg-white px-8 py-5 flex items-center justify-between gap-4 flex-wrap">
                  <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-[var(--color-sand)] rounded-full flex items-center justify-center shrink-0">
                      <span class="material-symbols-rounded text-[var(--color-accent)] text-[24px]">location_on</span>
                    </div>
                    <div>
                      <p class="font-extrabold text-[var(--color-text-dark)] text-base mb-1">{{ trip.departure_point || trip.location }}</p>
                      <p class="text-[var(--color-text-muted)] text-sm font-medium">{{ trip.latitude }}, {{ trip.longitude }}</p>
                    </div>
                  </div>
                  <a
                    :href="`https://www.google.com/maps/dir/?api=1&destination=${trip.latitude},${trip.longitude}`"
                    target="_blank"
                    class="bg-[var(--color-primary)] hover:bg-[var(--color-accent)] text-white text-sm font-extrabold px-6 py-3 rounded-full transition-all duration-300 flex items-center gap-2 shadow-md hover:-translate-y-0.5"
                  >
                    <span class="material-symbols-rounded text-[18px]">directions</span>
                    นำทาง
                  </a>
                </div>
              </div>

              <!-- Fallback: no lat/lng -->
              <div v-else class="h-[300px] bg-white rounded-[2rem] border border-gray-100 shadow-[0_10px_30px_rgba(0,0,0,0.03)] flex items-center justify-center">
                <div class="text-center">
                  <div class="w-20 h-20 bg-[var(--color-sand)] rounded-full flex items-center justify-center mx-auto mb-4">
                    <span class="material-symbols-rounded text-[var(--color-accent)] text-[40px]">pin_drop</span>
                  </div>
                  <p class="font-extrabold text-[var(--color-text-dark)] text-xl mb-2">{{ trip.departure_point }}</p>
                  <p class="text-[var(--color-text-muted)] text-base font-medium">จุดนัดหมายสำหรับทริปนี้</p>
                </div>
              </div>
              </section>

              <!-- Reviews Section -->
              <section id="reviews" class="pt-8 border-t border-gray-100">
                <div class="flex items-center justify-between mb-10">
                  <div>
                    <h3 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] tracking-tight mb-2">รีวิวจากผู้ร่วมทริป</h3>
                    <div class="flex items-center gap-3">
                      <div class="flex text-[#FFB020]">
                        <span v-for="star in 5" :key="star" class="material-symbols-rounded text-[20px]"
                          :style="star <= Math.round(trip.rating) ? 'font-variation-settings:\'FILL\' 1' : ''">
                          star
                        </span>
                      </div>
                      <span class="font-extrabold text-[var(--color-text-dark)] text-lg">{{ Number(trip.rating || 0).toFixed(1) }}</span>
                      <span class="text-[var(--color-text-muted)] font-medium text-sm">จาก {{ trip.review_count || 0 }} ความคิดเห็น</span>
                    </div>
                  </div>
                </div>

                <div v-if="reviewsLoading" class="flex justify-center py-12">
                  <div class="w-10 h-10 border-4 border-gray-200 border-t-[var(--color-accent)] rounded-full animate-spin"></div>
                </div>
                
                <div v-else-if="reviews.length > 0" class="space-y-8">
                  <div v-for="review in reviews" :key="review.id" class="bg-white p-6 md:p-8 rounded-[2rem] border border-gray-100 shadow-[0_10px_30px_rgba(0,0,0,0.02)] transition-all hover:shadow-[0_15px_40px_rgba(0,0,0,0.04)]">
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

                    <p class="text-[var(--color-text-mid)] leading-relaxed text-base font-medium mb-5 whitespace-pre-line">
                      {{ review.comment }}
                    </p>

                    <!-- Review Images -->
                    <div v-if="review.images && review.images.length > 0" class="flex flex-wrap gap-3 mb-5">
                      <div v-for="(img, idx) in review.images" :key="idx" 
                        class="w-24 h-24 md:w-32 md:h-32 rounded-2xl overflow-hidden border border-gray-100 cursor-pointer group relative">
                        <img :src="img" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110" />
                      </div>
                    </div>

                    <!-- Admin Reply -->
                    <div v-if="review.admin_reply" class="mt-6 bg-[var(--color-sand)]/50 rounded-2xl p-5 border-l-4 border-[var(--color-accent)] relative overflow-hidden">
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

                <div v-else class="text-center py-20 bg-white/50 rounded-[2.5rem] border border-dashed border-gray-200">
                  <div class="w-16 h-16 bg-[var(--color-sand)] rounded-full flex items-center justify-center mx-auto mb-4">
                    <span class="material-symbols-rounded text-gray-300 text-3xl">rate_review</span>
                  </div>
                  <p class="text-[var(--color-text-muted)] font-extrabold text-lg mb-1">ยังไม่มีการรีวิวสำหรับทริปนี้</p>
                  <p class="text-[var(--color-text-muted)] text-sm font-medium">ร่วมแชร์ประสบการณ์การเดินทางของคุณได้ หลังจากจบทริป</p>
                </div>
              </section>
            </div>

            <!-- Right Column: Sticky Booking Panel -->
          <aside class="lg:col-span-4">
            <div class="sticky top-28 space-y-6">

              <!-- Price Card -->
              <div class="bg-white p-8 rounded-[2rem] shadow-[0_20px_50px_rgba(0,0,0,0.08)] border border-gray-100 relative overflow-hidden z-10">
                <!-- Starting price -->
                <div class="flex items-end gap-2 mb-2">
                  <span class="text-4xl md:text-5xl font-black text-[var(--color-primary)] tracking-tight">฿{{ displayPrice.toLocaleString() }}</span>
                  <span class="text-[var(--color-text-muted)] text-base pb-1.5 font-bold uppercase tracking-wider">/ ท่าน</span>
                </div>
                <p v-if="isTrekking && regionOptions.length" class="text-sm font-medium text-gray-400 mb-8">
                  * ราคาขึ้นอยู่กับภูมิภาคที่ขึ้นรถ
                </p>
                <div v-else class="mb-8"></div>

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

                  <div v-else class="grid grid-cols-2 gap-2">
                    <button
                      v-for="r in regionOptions"
                      :key="r.region"
                      @click="selectRegion(r.region)"
                      class="text-left border-2 rounded-[1.25rem] p-3.5 transition-all duration-200"
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

                  <div v-else class="space-y-2">
                    <button
                      v-for="s in showAllSchedules ? schedules : schedules.slice(0, 5)"
                      :key="s.id"
                      @click="selectSchedule(s)"
                      class="schedule-btn w-full text-left border-2 rounded-[1.25rem] px-4 py-3 transition-all duration-300"
                      :class="selectedSchedule?.id === s.id
                        ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/5 shadow-md'
                        : 'border-gray-100 hover:border-[var(--color-accent)]/50 bg-white hover:bg-[var(--color-sand)] hover:shadow-sm'"
                    >
                      <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0 flex items-center gap-3">
                          <span class="material-symbols-rounded text-[var(--color-accent)] text-[18px] shrink-0">calendar_today</span>
                          <div>
                            <p class="font-extrabold text-[var(--color-text-dark)] text-sm leading-tight">{{ formatDate(s.departure_date) }}</p>
                            <p v-if="s.return_date !== s.departure_date" class="text-[var(--color-text-muted)] font-medium text-xs mt-0.5 flex items-center gap-1">
                              ถึง {{ formatDate(s.return_date) }}
                              <span class="inline-flex items-center gap-0.5 bg-white border border-gray-200 px-1.5 py-0.5 rounded text-[10px] font-bold text-[var(--color-text-dark)]">
                                <span class="material-symbols-rounded text-[11px]">schedule</span>
                                {{ s.duration_days || trip.duration_days }} วัน
                              </span>
                            </p>
                          </div>
                        </div>
                        <span
                          class="text-[11px] font-black px-2.5 py-1 rounded-full whitespace-nowrap shrink-0 border"
                          :class="s.available_seats > 3
                            ? 'bg-[#E8F5EC] text-[#2D7A4F] border-[#2D7A4F]/20'
                            : s.available_seats > 0
                              ? 'bg-amber-50 text-amber-600 border-amber-200'
                              : 'bg-red-50 text-red-600 border-red-200'"
                        >
                          {{ s.available_seats > 0 ? `ว่าง ${s.available_seats} ที่` : 'เต็มแล้ว' }}
                        </span>
                      </div>
                      <div v-if="selectedSchedule?.id === s.id" class="mt-2 pt-2 border-t border-gray-100 flex flex-wrap items-center gap-x-4 gap-y-1">
                        <div class="flex items-center gap-1.5">
                          <span class="material-symbols-rounded text-[14px] text-gray-400">{{ s.transport_type === 'van' ? 'airport_shuttle' : 'directions_boat' }}</span>
                          <span class="text-[11px] font-bold text-[var(--color-text-muted)]">{{ s.transport_type === 'van' ? 'รถตู้ VIP' : 'เรือสปีดโบ๊ท' }}</span>
                        </div>
                        <div v-if="s.license_plate" class="flex items-center gap-1.5">
                          <span class="material-symbols-rounded text-[14px] text-gray-400">tag</span>
                          <span class="text-[11px] font-extrabold text-[var(--color-text-dark)] bg-gray-100 px-1.5 py-0.5 rounded">{{ s.license_plate }}</span>
                        </div>
                      </div>
                    </button>
                    <button v-if="schedules.length > 5"
                      @click="showAllSchedules = !showAllSchedules"
                      class="w-full py-2.5 rounded-[1.25rem] border-2 border-dashed border-gray-200 text-sm font-bold text-gray-500 hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition-all flex items-center justify-center gap-2">
                      <span class="material-symbols-rounded text-[18px]">{{ showAllSchedules ? 'expand_less' : 'expand_more' }}</span>
                      {{ showAllSchedules ? 'แสดงน้อยลง' : `ดูทั้งหมด ${schedules.length} รอบ` }}
                    </button>
                  </div>
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

                  <div v-else class="space-y-2">
                    <button
                      v-for="s in showAllSchedules ? schedulesForRegion : schedulesForRegion.slice(0, 5)"
                      :key="s.id"
                      @click="selectSchedule(s)"
                      class="schedule-btn w-full text-left border-2 rounded-[1.25rem] px-4 py-3 transition-all duration-300"
                      :class="selectedSchedule?.id === s.id
                        ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/5 shadow-md'
                        : 'border-gray-100 hover:border-[var(--color-accent)]/50 bg-white hover:bg-[var(--color-sand)] hover:shadow-sm'"
                    >
                      <!-- Date + seats -->
                      <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0 flex items-center gap-3">
                          <span class="material-symbols-rounded text-[var(--color-accent)] text-[18px] shrink-0">calendar_today</span>
                          <div>
                            <p class="font-extrabold text-[var(--color-text-dark)] text-sm leading-tight">{{ formatDate(s.departure_date) }}</p>
                            <p v-if="s.return_date !== s.departure_date" class="text-[var(--color-text-muted)] font-medium text-xs mt-0.5 flex items-center gap-1">
                              ถึง {{ formatDate(s.return_date) }}
                              <span class="inline-flex items-center gap-0.5 bg-white border border-gray-200 px-1.5 py-0.5 rounded text-[10px] font-bold text-[var(--color-text-dark)]">
                                <span class="material-symbols-rounded text-[11px]">schedule</span>
                                {{ s.duration_days || trip.duration_days }} วัน
                              </span>
                            </p>
                          </div>
                        </div>
                        <span
                          class="text-[11px] font-black px-2.5 py-1 rounded-full whitespace-nowrap shrink-0 border"
                          :class="s.available_seats > 3
                            ? 'bg-[#E8F5EC] text-[#2D7A4F] border-[#2D7A4F]/20'
                            : s.available_seats > 0
                              ? 'bg-amber-50 text-amber-600 border-amber-200'
                              : 'bg-red-50 text-red-600 border-red-200'"
                        >
                          {{ s.available_seats > 0 ? `ว่าง ${s.available_seats} ที่` : 'เต็มแล้ว' }}
                        </span>
                      </div>

                      <!-- Pickup point for this region (always shown) -->
                      <div v-if="(s.pickup_points || []).find(pt => pt.region === selectedRegion)" class="mt-2 pl-9">
                        <template v-for="pt in s.pickup_points" :key="pt.id">
                          <div v-if="pt.region === selectedRegion" class="text-xs text-[var(--color-text-dark)] font-bold flex items-start gap-1.5">
                            <span class="material-symbols-rounded text-red-400 text-[14px] shrink-0 mt-0.5">pin_drop</span>
                            <span>{{ pt.pickup_location }}<span v-if="pt.notes" class="text-[var(--color-text-muted)] font-medium"> · {{ pt.notes }}</span></span>
                          </div>
                        </template>
                      </div>

                      <!-- Price for this region -->
                      <div v-if="(s.pickup_points || []).find(pt => pt.region === selectedRegion)" class="mt-1.5 pl-9 flex items-center justify-between">
                        <template v-for="pt in s.pickup_points" :key="'price-' + pt.id">
                          <span v-if="pt.region === selectedRegion" class="text-[11px] font-black text-[var(--color-accent)]">฿{{ Number(pt.price).toLocaleString() }} / ท่าน</span>
                        </template>
                        <template v-for="pt in s.pickup_points" :key="'map-' + pt.id">
                          <a v-if="pt.region === selectedRegion && pt.map_url" :href="pt.map_url" target="_blank"
                            @click.stop
                            class="text-[10px] text-[var(--color-primary)] hover:text-[var(--color-accent)] font-bold flex items-center gap-1 transition-colors">
                            <span class="material-symbols-rounded text-[12px]">map</span> แผนที่
                          </a>
                        </template>
                      </div>

                      <!-- Transport info when selected -->
                      <div v-if="selectedSchedule?.id === s.id" class="mt-2 pt-2 border-t border-gray-100 flex flex-wrap items-center gap-x-4 gap-y-1 pl-9">
                        <div class="flex items-center gap-1.5">
                          <span class="material-symbols-rounded text-[14px] text-gray-400">{{ s.transport_type === 'van' ? 'airport_shuttle' : 'directions_boat' }}</span>
                          <span class="text-[11px] font-bold text-[var(--color-text-muted)]">{{ s.transport_type === 'van' ? 'รถตู้ VIP' : 'เรือสปีดโบ๊ท' }}</span>
                        </div>
                        <div v-if="s.license_plate" class="flex items-center gap-1.5">
                          <span class="material-symbols-rounded text-[14px] text-gray-400">tag</span>
                          <span class="text-[11px] font-extrabold text-[var(--color-text-dark)] bg-gray-100 px-1.5 py-0.5 rounded">{{ s.license_plate }}</span>
                        </div>
                        <div v-if="s.vehicle_color" class="flex items-center gap-1.5">
                          <div class="w-3 h-3 rounded-full border border-gray-200 shadow-sm shrink-0" :style="{ backgroundColor: s.vehicle_color }"></div>
                          <span class="text-[11px] font-bold text-[var(--color-text-muted)]">{{ s.vehicle_color }}</span>
                        </div>
                      </div>
                    </button>

                    <button v-if="schedulesForRegion.length > 5"
                      @click="showAllSchedules = !showAllSchedules"
                      class="w-full py-2.5 rounded-[1.25rem] border-2 border-dashed border-gray-200 text-sm font-bold text-gray-500 hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition-all flex items-center justify-center gap-2">
                      <span class="material-symbols-rounded text-[18px]">{{ showAllSchedules ? 'expand_less' : 'expand_more' }}</span>
                      {{ showAllSchedules ? 'แสดงน้อยลง' : `ดูทั้งหมด ${schedulesForRegion.length} รอบ` }}
                    </button>
                  </div>
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

                <!-- ── Book Now ── -->
                <div v-if="selectedSchedule">
                  <router-link
                    v-if="!isTrekking || (selectedSchedule && selectedPickup)"
                    :to="`/booking/${selectedSchedule.id}`"
                    class="block text-center bg-[var(--color-primary)] text-white py-4 rounded-full font-extrabold text-lg hover:bg-[var(--color-accent)] transition-all duration-300 shadow-[0_10px_20px_rgba(13,43,30,0.2)] hover:shadow-[0_15px_30px_rgba(45,122,79,0.3)] hover:-translate-y-1"
                  >
                    ดำเนินการจองทริป
                  </router-link>
                  <button v-else disabled
                    class="w-full py-4 rounded-full font-extrabold text-lg bg-gray-100 text-gray-400 cursor-not-allowed text-center border border-gray-200">
                    {{ !selectedRegion ? 'กรุณาเลือกภูมิภาค' : !selectedSchedule ? 'กรุณาเลือกวันเดินทาง' : 'กรุณาเลือกจุดขึ้นรถ' }}
                  </button>
                  <p class="text-xs font-medium text-[var(--color-text-muted)] mt-4 text-center flex items-center justify-center gap-1.5">
                    <span class="material-symbols-rounded text-[16px]">verified_user</span>
                    ยกเลิกฟรีล่วงหน้าอย่างน้อย 48 ชั่วโมง
                  </p>
                </div>
                <div v-else class="text-center py-4 bg-gray-50 rounded-[1.25rem] border border-dashed border-gray-300">
                  <p class="text-sm font-bold text-gray-500">{{ isTrekking ? (selectedRegion ? 'โปรดเลือกวันเดินทาง' : 'โปรดเลือกภูมิภาคก่อน') : 'โปรดเลือกวันเดินทางเพื่อจอง' }}</p>
                </div>
              </div>

              <!-- Urgency Card -->
              <div v-if="urgentSeats" class="bg-[#FFF8EE] p-6 rounded-[1.5rem] border border-[#C8963E]/30 flex items-center gap-5 shadow-sm animate-fade-in-up">
                <div class="w-14 h-14 rounded-full bg-[#C8963E] flex items-center justify-center text-white shrink-0 shadow-md">
                  <span class="material-symbols-rounded text-[28px]">local_fire_department</span>
                </div>
                <div>
                  <p class="font-extrabold text-[var(--color-text-dark)] text-lg mb-0.5">จองด่วน!</p>
                  <p class="text-sm font-bold text-[#A87830]">เหลือเพียง {{ urgentSeats }} ที่นั่งสุดท้าย</p>
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
      </div>
    </div>

    <!-- Not Found -->
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
      <div v-if="showMustKnowModal && trip?.must_know" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-black/60 backdrop-blur-md transition-opacity" @click="showMustKnowModal = false"></div>
        
        <!-- Modal Content -->
        <div class="bg-white rounded-[1.5rem] sm:rounded-[2rem] w-full max-w-md relative z-10 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-300">
          <!-- Close Button -->
          <button @click="showMustKnowModal = false" class="absolute top-3 right-3 sm:top-5 sm:right-5 w-8 h-8 sm:w-9 sm:h-9 rounded-full bg-gray-100 hover:bg-gray-200 flex items-center justify-center transition-all active:scale-95 z-20">
            <span class="material-symbols-rounded text-gray-400 text-[20px] sm:text-2xl">close</span>
          </button>

          <!-- Top Banner -->
          <div class="bg-amber-500 p-5 sm:p-7 text-white relative">
            <div class="absolute -right-6 -bottom-6 w-24 h-24 bg-white/10 rounded-full blur-2xl"></div>
            <div class="w-10 h-10 sm:w-12 sm:h-12 rounded-xl sm:rounded-2xl bg-white/20 flex items-center justify-center mb-3 sm:mb-4 border border-white/20">
              <span class="material-symbols-rounded text-2xl sm:text-3xl" style="font-variation-settings:'FILL' 1">campaign</span>
            </div>
            <h3 class="text-xl sm:text-2xl font-black tracking-tight">ข้อควรรู้สำหรับทริปนี้</h3>
          </div>

          <div class="p-5 sm:p-7 space-y-4 sm:space-y-5">
            <!-- Items Selection / Info -->
            <div v-if="trip.must_know.items && trip.must_know.items.length" class="space-y-3">
              <p class="text-[10px] sm:text-[11px] font-black text-gray-400 uppercase tracking-widest pl-1">รายการเพิ่มเติม / ราคาพิเศษ</p>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                <div v-for="(item, idx) in trip.must_know.items" :key="idx" 
                  class="flex items-center justify-between p-3 rounded-xl bg-gray-50 border border-gray-100 group transition-all hover:bg-white hover:shadow-md hover:border-amber-200">
                  <div class="flex items-center gap-2.5 overflow-hidden">
                    <div class="w-8 h-8 rounded-lg bg-white flex items-center justify-center shadow-sm border border-gray-100 shrink-0 group-hover:scale-110 transition-transform">
                      <span class="material-symbols-rounded text-lg text-amber-600">tips_and_updates</span>
                    </div>
                    <span class="font-extrabold text-gray-800 text-xs sm:text-sm truncate">{{ item.name }}</span>
                  </div>
                  <span class="font-black text-amber-600 bg-amber-50 px-2.5 py-1 rounded-lg border border-amber-100 text-[10px] sm:text-xs shrink-0 ml-2">฿{{ item.price }}</span>
                </div>
              </div>
            </div>

            <!-- Notes / Remarks -->
            <div v-if="trip.must_know.remarks" class="p-4 rounded-xl bg-amber-50/50 border border-amber-100 relative overflow-hidden">
               <div class="flex items-start gap-2.5 relative z-10">
                 <span class="material-symbols-rounded text-amber-500 mt-0.5 text-lg sm:text-xl" style="font-variation-settings:'FILL' 1">info</span>
                 <div class="flex-1">
                   <p class="text-[10px] sm:text-[11px] font-black text-amber-700 mb-1 uppercase tracking-wide">หมายเหตุเพิ่มเติม</p>
                   <p class="text-[11px] sm:text-xs text-gray-700 leading-relaxed font-bold">{{ trip.must_know.remarks }}</p>
                 </div>
               </div>
            </div>

            <button @click="showMustKnowModal = false" class="w-full bg-[var(--color-primary)] text-white font-extrabold py-3.5 sm:py-4 rounded-xl hover:bg-[var(--color-accent)] active:scale-[0.98] transition-all shadow-lg shadow-[var(--color-primary)]/20 text-sm sm:text-base">
              เข้าใจแล้ว เริ่มจองทริปกันเลยครับ
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import api from '../lib/axios';

const route = useRoute();
const trip = ref(null);
const schedules = ref([]);
const showAllSchedules = ref(false);
const selectedSchedule = ref(null);
const selectedPickup = ref(null);
const selectedRegion = ref(null);
const loading = ref(true);
const schedulesLoading = ref(false);
const reviews = ref([]);
const reviewsLoading = ref(false);
const showMustKnowModal = ref(false);
const distanceLoading = ref(false);
const distanceData = ref([]);

const isTrekking = computed(() => trip.value?.type === 'trekking');

const allPickupPoints = computed(() => {
  if (!isTrekking.value) return [];
  const pts = selectedSchedule.value?.pickup_points
    || schedules.value[0]?.pickup_points
    || [];
  return pts;
});

const regionOptions = computed(() => {
  if (!isTrekking.value) return [];
  const map = new Map();
  schedules.value.forEach(s => {
    (s.pickup_points || []).forEach(pt => {
      if (!map.has(pt.region)) {
        map.set(pt.region, {
          region: pt.region,
          region_label: pt.region_label,
          min_price: Number(pt.price),
          schedule_count: 0,
        });
      } else {
        const existing = map.get(pt.region);
        if (Number(pt.price) < existing.min_price) existing.min_price = Number(pt.price);
      }
      map.get(pt.region).schedule_count++;
    });
  });
  return [...map.values()];
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

const urgentSeats = computed(() => {
  if (!schedules.value.length) return 0;
  const min = Math.min(...schedules.value.map(s => s.available_seats));
  return min > 0 && min <= 5 ? min : 0;
});

function formatDate(d) {
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

const displayPrice = computed(() => {
  if (selectedPickup.value) return Number(selectedPickup.value.price);
  if (selectedSchedule.value?.price) return Number(selectedSchedule.value.price);
  return Number(trip.value?.price_per_person || 0);
});

function selectRegion(region) {
  selectedRegion.value = region;
  selectedSchedule.value = null;
  selectedPickup.value = null;
}

function selectSchedule(s) {
  selectedSchedule.value = s;
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

onMounted(async () => {
  try {
    const res = await api.get(`/trips/${route.params.slug}`);
    trip.value = res.data.data;
    typeLabel.value = typeMap[trip.value.type]?.label || trip.value.type;
    typeBadgeClass.value = typeMap[trip.value.type]?.class || 'bg-[#6B8F7A] text-white';
    diffLabel.value = diffMap[trip.value.difficulty] || trip.value.difficulty;

    schedulesLoading.value = true;
    const sRes = await api.get(`/trips/${route.params.slug}/schedules`);
    schedules.value = sRes.data.data;
    
    // Show must know modal if exists
    if (trip.value?.must_know && (trip.value.must_know.items?.length || trip.value.must_know.remarks)) {
      setTimeout(() => {
        showMustKnowModal.value = true;
      }, 500);
    }

    await fetchReviews();
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
    schedulesLoading.value = false;
  }
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

async function fetchReviews() {
  if (!trip.value?.id) return;
  reviewsLoading.value = true;
  try {
    const res = await api.get('/reviews', { params: { trip_id: trip.value.id, per_page: 10 } });
    reviews.value = res.data.data;
  } catch (error) {
    console.error('Failed to fetch reviews:', error);
  } finally {
    reviewsLoading.value = false;
  }
}
</script>

<style scoped>
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
  img {
    animation: none !important;
    transition: none !important;
    opacity: 1 !important;
    transform: none !important;
  }
}
</style>
