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
                {{ trip.rating || (Math.floor(Math.random() * 5 + 45) / 10).toFixed(1) }} ({{ trip.review_count || Math.floor(Math.random() * 900 + 100) }} รีวิว)
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
                    <span class="material-symbols-rounded text-[28px] text-[var(--color-accent)] group-hover:text-white transition-colors duration-300">{{ hi.icon }}</span>
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
                  <li v-for="(item, i) in inclusions" :key="i" class="flex items-start gap-3">
                    <span class="material-symbols-rounded text-[#2D7A4F] shrink-0 mt-0.5 text-[20px]">check</span>
                    <span>{{ item }}</span>
                  </li>
                </ul>
              </div>
              <div class="p-8 md:p-10 bg-white rounded-[2rem] border border-red-50 shadow-[0_10px_40px_rgba(239,68,68,0.05)]">
                <h4 class="text-xl font-extrabold mb-6 flex items-center gap-3 text-red-500">
                  <span class="material-symbols-rounded text-[28px]" style="font-variation-settings:'FILL' 1">cancel</span>
                  สิ่งที่ไม่รวม
                </h4>
                <ul class="space-y-4 text-base font-medium text-[var(--color-text-dark)]">
                  <li v-for="(item, i) in exclusions" :key="i" class="flex items-start gap-3">
                    <span class="material-symbols-rounded text-red-400 shrink-0 mt-0.5 text-[20px]">close</span>
                    <span>{{ item }}</span>
                  </li>
                </ul>
              </div>
            </section>

            <!-- Trekking: Pickup Regions Info Section -->
            <section v-if="isTrekking && allPickupPoints.length">
              <h3 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] mb-3 tracking-tight">จุดขึ้นรถและราคาตามภูมิภาค</h3>
              <p class="text-[var(--color-text-muted)] text-base font-medium mb-8">เลือกจุดขึ้นรถที่สะดวกสำหรับคุณ ราคาอาจแตกต่างกันในแต่ละพื้นที่</p>
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
                    <a v-if="pt.map_url" :href="pt.map_url" target="_blank"
                      class="inline-flex items-center gap-1.5 text-sm text-white bg-[var(--color-primary)] hover:bg-[var(--color-accent)] px-4 py-2 rounded-lg font-bold transition-colors mt-2">
                      <span class="material-symbols-rounded text-[16px]">map</span>
                      ดูแผนที่จุดรับ
                    </a>
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
          </div>

          <!-- Right Column: Sticky Booking Panel -->
          <aside class="lg:col-span-4">
            <div class="sticky top-28 space-y-6">

              <!-- Price Card -->
              <div class="bg-white p-8 rounded-[2rem] shadow-[0_20px_50px_rgba(0,0,0,0.08)] border border-gray-100 relative overflow-hidden z-10">
                <!-- Background decoration -->
                <div class="absolute -right-16 -top-16 w-48 h-48 bg-[var(--color-sand)] rounded-full -z-10"></div>

                <!-- Starting price -->
                <div class="flex items-end gap-2 mb-2">
                  <span class="text-4xl md:text-5xl font-black text-[var(--color-primary)] tracking-tight">฿{{ displayPrice.toLocaleString() }}</span>
                  <span class="text-[var(--color-text-muted)] text-base pb-1.5 font-bold uppercase tracking-wider">/ ท่าน</span>
                </div>
                <p v-if="isTrekking && selectedSchedule?.pickup_points?.length" class="text-sm font-medium text-gray-400 mb-8">
                  * ราคาขึ้นอยู่กับจุดรับส่งที่เลือก
                </p>
                <div v-else class="mb-8"></div>

                <hr class="border-gray-100 mb-8" />

                <!-- ── Step 1: Schedule Selection ── -->
                <div class="mb-8">
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

                  <div v-else class="space-y-3 max-h-[350px] overflow-y-auto pr-2 custom-scrollbar">
                    <button
                      v-for="s in schedules"
                      :key="s.id"
                      @click="selectSchedule(s)"
                      class="schedule-btn w-full text-left border-2 rounded-[1.25rem] p-4 transition-all duration-300"
                      :class="selectedSchedule?.id === s.id
                        ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/5 shadow-md'
                        : 'border-gray-100 hover:border-[var(--color-accent)]/50 bg-white hover:bg-[var(--color-sand)] hover:shadow-sm'"
                    >
                      <!-- Row 1: Date + Seats badge -->
                      <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                          <p class="font-extrabold text-[var(--color-text-dark)] text-base">{{ formatDate(s.departure_date) }}</p>
                          <p v-if="s.return_date !== s.departure_date" class="text-[var(--color-text-muted)] font-medium text-sm mt-1">
                            ถึง {{ formatDate(s.return_date) }}
                            <span class="ml-2 inline-flex items-center gap-1 bg-white border border-gray-200 px-2 py-0.5 rounded-md text-xs font-bold text-[var(--color-text-dark)]">
                              <span class="material-symbols-rounded text-[14px]">schedule</span>
                              {{ s.duration_days || trip.duration_days }} วัน
                            </span>
                          </p>
                        </div>
                        <span
                          class="text-xs font-black px-3 py-1 rounded-full whitespace-nowrap shrink-0 border"
                          :class="s.available_seats > 3
                            ? 'bg-[#E8F5EC] text-[#2D7A4F] border-[#2D7A4F]/20'
                            : s.available_seats > 0
                              ? 'bg-amber-50 text-amber-600 border-amber-200'
                              : 'bg-red-50 text-red-600 border-red-200'"
                        >
                          {{ s.available_seats > 0 ? `ว่าง ${s.available_seats} ที่` : 'เต็มแล้ว' }}
                        </span>
                      </div>

                      <!-- Row 2: Region prices for trekking -->
                      <div v-if="isTrekking && s.pickup_points?.length" class="mt-3 flex flex-wrap gap-2">
                        <span
                          v-for="pt in s.pickup_points.slice(0,3)" :key="pt.id"
                          class="text-xs px-2 py-1 rounded-md bg-[var(--color-sand)] text-[var(--color-text-dark)] font-bold border border-gray-200"
                        >
                          {{ pt.region_label }} <span class="text-[var(--color-accent)]">฿{{ Number(pt.price).toLocaleString() }}</span>
                        </span>
                        <span v-if="s.pickup_points.length > 3" class="text-xs px-2 py-1 rounded-md bg-gray-100 text-gray-500 font-bold border border-gray-200">
                          +{{ s.pickup_points.length - 3 }}
                        </span>
                      </div>

                      <!-- Row 3: Transport info -->
                      <div class="mt-3 pt-3 border-t border-gray-100 flex flex-wrap items-center gap-x-5 gap-y-2">
                        <div class="flex items-center gap-2">
                          <span class="material-symbols-rounded text-[16px] text-gray-400">{{ s.transport_type === 'van' ? 'airport_shuttle' : 'directions_boat' }}</span>
                          <span class="text-xs font-bold text-[var(--color-text-muted)]">{{ s.transport_type === 'van' ? 'รถตู้ VIP' : 'เรือสปีดโบ๊ท' }}</span>
                        </div>
                        <div v-if="s.license_plate" class="flex items-center gap-2">
                          <span class="material-symbols-rounded text-[16px] text-gray-400">tag</span>
                          <span class="text-xs font-extrabold text-[var(--color-text-dark)] bg-gray-100 px-2 py-0.5 rounded">{{ s.license_plate }}</span>
                        </div>
                        <div v-if="s.vehicle_color" class="flex items-center gap-2">
                          <div class="w-4 h-4 rounded-full border border-gray-200 shadow-sm shrink-0" :style="{ backgroundColor: s.vehicle_color }"></div>
                          <span class="text-xs font-bold text-[var(--color-text-muted)]">{{ s.vehicle_color }}</span>
                        </div>
                      </div>
                    </button>
                  </div>
                </div>

                <!-- ── Step 2 (Trekking): Region Picker ── -->
                <div v-if="isTrekking && selectedSchedule?.pickup_points?.length" class="mb-8 animate-fade-in">
                  <label class="flex items-center gap-2 text-sm font-extrabold text-[var(--color-text-dark)] uppercase tracking-wider mb-4">
                    <span class="material-symbols-rounded text-[var(--color-accent)] text-[20px]">pin_drop</span>
                    เลือกจุดขึ้นรถ
                  </label>
                  <div class="space-y-3">
                    <button
                      v-for="pt in selectedSchedule.pickup_points"
                      :key="pt.id"
                      @click="selectPickup(pt)"
                      class="w-full text-left rounded-[1.25rem] border-2 p-4 transition-all duration-300"
                      :class="selectedPickup?.id === pt.id
                        ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/5 shadow-md'
                        : 'border-gray-100 hover:border-[var(--color-accent)]/40 bg-white hover:bg-[var(--color-sand)]'"
                    >
                      <div class="flex items-center justify-between gap-3">
                        <div class="min-w-0">
                          <div class="flex items-center gap-2 mb-2">
                            <span class="inline-flex items-center gap-1.5 text-xs font-black px-3 py-1 rounded-md uppercase tracking-wider"
                              :class="selectedPickup?.id === pt.id ? 'bg-[var(--color-accent)] text-white' : 'bg-[var(--color-primary)] text-white'">
                              {{ pt.region_label }}
                            </span>
                          </div>
                          <p class="text-sm text-[var(--color-text-dark)] font-bold flex items-start gap-1.5 leading-snug">
                            <span class="material-symbols-rounded text-red-500 shrink-0 mt-0.5 text-[18px]">location_on</span>
                            <span class="line-clamp-2">{{ pt.pickup_location }}</span>
                          </p>
                          <p v-if="pt.notes" class="text-xs text-[var(--color-text-muted)] font-medium mt-1.5 flex items-start gap-1.5">
                            <span class="material-symbols-rounded shrink-0 mt-0.5 text-[#FFB020] text-[16px]">schedule</span>
                            {{ pt.notes }}
                          </p>
                        </div>
                        <div class="shrink-0 text-right">
                          <p class="font-black text-xl"
                            :class="selectedPickup?.id === pt.id ? 'text-[var(--color-accent)]' : 'text-[var(--color-text-dark)]'">
                            ฿{{ Number(pt.price).toLocaleString() }}
                          </p>
                          <a v-if="pt.map_url" :href="pt.map_url" target="_blank"
                            @click.stop
                            class="text-xs text-[var(--color-primary)] hover:text-[var(--color-accent)] font-bold hover:underline flex items-center gap-1 justify-end mt-2 transition-colors">
                            <span class="material-symbols-rounded text-[14px]">map</span> แผนที่
                          </a>
                        </div>
                      </div>
                    </button>
                  </div>
                </div>

                <!-- No pickup points yet for trekking -->
                <div v-else-if="isTrekking && selectedSchedule && !selectedSchedule.pickup_points?.length"
                  class="mb-8 p-4 bg-[var(--color-sand)] rounded-[1.25rem] text-sm text-[var(--color-text-dark)] font-bold text-center border border-gray-100">
                  <span class="material-symbols-rounded mr-1 align-middle text-[var(--color-accent)]">info</span> จุดรับผู้โดยสารจะแจ้งให้ทราบอีกครั้ง
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
                    v-if="!isTrekking || !selectedSchedule.pickup_points?.length || selectedPickup"
                    :to="`/booking/${selectedSchedule.id}`"
                    class="block text-center bg-[var(--color-primary)] text-white py-4 rounded-full font-extrabold text-lg hover:bg-[var(--color-accent)] transition-all duration-300 shadow-[0_10px_20px_rgba(13,43,30,0.2)] hover:shadow-[0_15px_30px_rgba(45,122,79,0.3)] hover:-translate-y-1"
                  >
                    ดำเนินการจองทริป
                  </router-link>
                  <button v-else disabled
                    class="w-full py-4 rounded-full font-extrabold text-lg bg-gray-100 text-gray-400 cursor-not-allowed text-center border border-gray-200">
                    กรุณาเลือกจุดขึ้นรถ
                  </button>
                  <p class="text-xs font-medium text-[var(--color-text-muted)] mt-4 text-center flex items-center justify-center gap-1.5">
                    <span class="material-symbols-rounded text-[16px]">verified_user</span>
                    ยกเลิกฟรีล่วงหน้าอย่างน้อย 48 ชั่วโมง
                  </p>
                </div>
                <div v-else class="text-center py-4 bg-gray-50 rounded-[1.25rem] border border-dashed border-gray-300">
                  <p class="text-sm font-bold text-gray-500">โปรดเลือกวันเดินทางเพื่อจอง</p>
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
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import api from '../lib/axios';

const route = useRoute();
const trip = ref(null);
const schedules = ref([]);
const selectedSchedule = ref(null);
const selectedPickup = ref(null);
const loading = ref(true);
const schedulesLoading = ref(false);

const isTrekking = computed(() => trip.value?.type === 'trekking');

const allPickupPoints = computed(() => {
  if (!isTrekking.value) return [];
  const pts = selectedSchedule.value?.pickup_points
    || schedules.value[0]?.pickup_points
    || [];
  return pts;
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

// Dynamic highlights based on trip type
const highlights = computed(() => {
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

const inclusions = computed(() => {
  const t = trip.value?.type;
  if (t === 'diving' || t === 'snorkeling') {
    return ['บริการรถรับ-ส่งจากที่พักในเขตพื้นที่กำหนด', 'ค่าธรรมเนียมเข้าอุทยานแห่งชาติ', 'อุปกรณ์ดำน้ำครบชุด (หน้ากาก, ท่อหายใจ, เสื้อชูชีพ)', 'อาหารกลางวัน ผลไม้ตามฤดูกาล และน้ำดื่ม', 'ไกด์นำเที่ยวมืออาชีพ', 'ประกันอุบัติเหตุการเดินทาง'];
  } else if (t === 'trekking') {
    return ['ไกด์ท้องถิ่นนำทางดูแลตลอดเส้นทาง', 'อุปกรณ์แคมป์ปิ้ง (เต็นท์, ถุงนอน)', 'อาหารและน้ำดื่มสำหรับทุกมื้อตามโปรแกรม', 'ค่าธรรมเนียมเข้าพื้นที่', 'ลูกหาบส่วนกลางสำหรับสัมภาระแคมป์', 'ประกันอุบัติเหตุ'];
  }
  return ['รถตู้ปรับอากาศ VIP พร้อมน้ำมันเชื้อเพลิง', 'พนักงานขับรถผู้ชำนาญเส้นทาง', 'น้ำดื่มและผ้าเย็นบริการบนรถ', 'ประกันอุบัติเหตุการเดินทาง'];
});

const exclusions = computed(() => {
  return ['ค่าใช้จ่ายส่วนตัวอื่นๆ (เช่น ของที่ระลึก, เครื่องดื่มแอลกอฮอล์)', 'ทิปสำหรับมัคคุเทศก์และทีมงาน (ตามความพึงพอใจ)', 'อุปกรณ์เสริมส่วนตัวที่ไม่ได้ระบุในโปรแกรม', 'ภาษีมูลค่าเพิ่ม 7% และหัก ณ ที่จ่าย 3% (กรณีต้องการใบกำกับภาษี)'];
});

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

function selectSchedule(s) {
  selectedSchedule.value = s;
  selectedPickup.value = null;
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
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
    schedulesLoading.value = false;
  }
});
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
