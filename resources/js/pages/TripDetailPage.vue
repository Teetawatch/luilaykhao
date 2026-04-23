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
            
            <h1 class="text-white text-2xl md:text-4xl lg:text-5xl font-black mb-4 leading-tight drop-shadow-2xl tracking-tight truncate" :title="trip.title">
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

            <section class="description-section bg-white p-8 md:p-12 rounded-[2rem] border border-gray-100 shadow-[0_10px_40px_rgba(0,0,0,0.03)]">
              <h2 class="text-3xl md:text-4xl font-extrabold text-[var(--color-text-dark)] mb-6 tracking-tight">เกี่ยวกับทริปนี้</h2>
              <p class="text-[var(--color-text-mid)] leading-relaxed text-base md:text-lg whitespace-pre-line font-medium">{{ trip.description }}</p>
            </section>

            <!-- Itinerary (Day by Day) -->
            <section v-if="trip.itinerary && trip.itinerary.length > 0" class="itinerary-section">
              <div class="flex items-center justify-between mb-8">
                <h3 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] tracking-tight">แผนการเดินทาง</h3>
                <span class="text-xs font-bold text-[var(--color-text-muted)] bg-[var(--color-sand)] px-3 py-1.5 rounded-full border border-gray-100">
                  {{ trip.itinerary.length }} วัน
                </span>
              </div>
              
              <div class="space-y-4">
                <div 
                  v-for="(item, idx) in trip.itinerary" 
                  :key="idx" 
                  class="itinerary-day-card bg-white rounded-[1.5rem] border border-gray-100 shadow-[0_10px_30px_rgba(0,0,0,0.02)] overflow-hidden transition-all duration-300"
                  :class="{'ring-2 ring-[var(--color-accent)]/10 shadow-[0_15px_40px_rgba(0,0,0,0.05)]': openDays.includes(item.day)}"
                >
                  <div 
                    @click="toggleDay(item.day)"
                    class="p-6 md:p-8 flex items-center justify-between cursor-pointer group"
                  >
                    <div class="flex items-center gap-5 md:gap-8">
                      <div class="day-number-circle w-12 h-12 md:w-16 md:h-16 rounded-3xl bg-[var(--color-sand)] flex flex-col items-center justify-center transition-colors group-hover:bg-[var(--color-accent)]/10"
                        :class="{'!bg-[var(--color-accent)] text-white': openDays.includes(item.day)}">
                        <span class="text-[10px] font-black uppercase tracking-widest opacity-70">Day</span>
                        <span class="text-xl md:text-2xl font-black leading-none">{{ item.day }}</span>
                      </div>
                      <div>
                        <h4 class="text-lg md:text-xl font-extrabold text-[var(--color-text-dark)] group-hover:text-[var(--color-accent)] transition-colors">{{ item.title }}</h4>
                        <p v-if="!openDays.includes(item.day)" class="text-sm text-[var(--color-text-muted)] font-medium mt-1 line-clamp-1 max-w-[200px] md:max-w-md">
                          {{ item.description }}
                        </p>
                      </div>
                    </div>
                    <div class="w-10 h-10 rounded-full bg-gray-50 flex items-center justify-center text-gray-400 group-hover:bg-[var(--color-sand)] group-hover:text-[var(--color-accent)] transition-all"
                      :class="{'rotate-180 bg-[var(--color-accent)]/10 !text-[var(--color-accent)]': openDays.includes(item.day)}">
                      <span class="material-symbols-rounded">expand_more</span>
                    </div>
                  </div>
                  
                  <div v-show="openDays.includes(item.day)" class="px-6 pb-8 md:px-8 md:pb-10 md:ml-[104px] animate-fade-in">
                    <div class="w-full h-px bg-gray-100 mb-6"></div>
                    <p class="text-[var(--color-text-mid)] leading-relaxed text-base md:text-lg font-medium whitespace-pre-line">
                      {{ item.description }}
                    </p>
                  </div>
                </div>
              </div>
            </section>

            <!-- Preparations Section -->
            <section v-if="trip.preparations && trip.preparations.length > 0" class="preparations-section bg-white p-8 md:p-12 rounded-[2rem] border border-gray-100 shadow-[0_10px_40px_rgba(0,0,0,0.03)] relative overflow-hidden">
              <div class="absolute -right-12 -top-12 w-48 h-48 bg-[var(--color-sand)] rounded-full blur-3xl opacity-50"></div>
              <div class="relative z-10">
                <div class="flex items-center gap-4 mb-8">
                  <div class="w-12 h-12 rounded-2xl bg-[var(--color-sand)] flex items-center justify-center text-[var(--color-accent)]">
                    <span class="material-symbols-rounded text-3xl">backpack</span>
                  </div>
                  <h3 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] tracking-tight">การเตรียมตัวและสิ่งที่ต้องเตรียม</h3>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-4">
                  <div v-for="(item, idx) in trip.preparations" :key="idx" class="flex items-start gap-4 p-4 rounded-2xl hover:bg-[var(--color-sand)]/30 transition-colors group">
                    <div class="w-8 h-8 rounded-full bg-white shadow-sm border border-gray-100 flex items-center justify-center shrink-0 mt-0.5 group-hover:scale-110 transition-transform">
                      <span class="material-symbols-rounded text-[var(--color-accent)] text-lg">check</span>
                    </div>
                    <p class="text-[var(--color-text-mid)] font-bold text-base md:text-lg leading-relaxed pt-0.5">{{ item }}</p>
                  </div>
                </div>
              </div>
            </section>

            <!-- Highlights -->
            <section>
              <div class="flex items-center justify-between mb-8 flex-wrap gap-4">
                <h3 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] tracking-tight">จุดเด่นของทริป</h3>
                <button 
                  @click="showAvailabilityModal = true" 
                  class="flex items-center gap-2 text-sm font-black text-[var(--color-accent)] bg-white px-5 py-2.5 rounded-full border border-[var(--color-accent)]/20 hover:bg-[var(--color-accent)] hover:text-white transition-all shadow-lg shadow-black/5 active:scale-95 group"
                >
                  <span class="material-symbols-rounded text-xl transition-transform group-hover:rotate-12">calendar_month</span>
                  เช็ครอบที่ยังว่าง
                </button>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div v-for="(hi, idx) in highlights" :key="idx" 
                  @click="showAvailabilityModal = true"
                  class="bg-white p-6 rounded-[1.5rem] border border-gray-100 shadow-[0_10px_30px_rgba(0,0,0,0.03)] hover:-translate-y-1 hover:border-[var(--color-accent)]/30 hover:shadow-[0_20px_40px_rgba(0,0,0,0.06)] transition-all duration-300 group flex gap-5 items-start cursor-pointer">
                  <div class="w-14 h-14 rounded-2xl bg-[var(--color-sand)] group-hover:bg-[var(--color-accent)] transition-colors duration-300 flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-[28px] text-[var(--color-accent)] group-hover:text-white transition-colors duration-300">{{ hi.icon || 'star' }}</span>
                  </div>
                  <div class="flex-grow">
                    <h4 class="text-lg font-extrabold text-[var(--color-text-dark)] mb-2 group-hover:text-[var(--color-accent)] transition-colors">{{ hi.title }}</h4>
                    <p class="text-sm text-[var(--color-text-muted)] font-medium leading-relaxed">{{ hi.desc }}</p>
                  </div>
                  <div class="opacity-0 group-hover:opacity-100 transition-opacity self-center text-[var(--color-accent)]">
                    <span class="material-symbols-rounded">chevron_right</span>
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
                      :class="selectedSchedule && (!isTrekking || selectedPickup)
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

                  <div v-else class="space-y-3">
                    <label class="inline-flex items-center gap-2 text-xs font-bold text-[var(--color-text-muted)] cursor-pointer select-none">
                      <input v-model="onlyAvailableSchedules" type="checkbox" class="rounded border-gray-300 text-[var(--color-accent)] focus:ring-[var(--color-accent)]" />
                      แสดงเฉพาะรอบที่ยังว่าง
                    </label>

                    <div v-if="filteredSchedules.length === 0" class="bg-[var(--color-sand)] rounded-[1.25rem] p-4 text-center border border-gray-100">
                      <p class="text-[var(--color-text-dark)] font-bold text-sm">ไม่พบรอบที่ตรงเงื่อนไข</p>
                      <p class="text-gray-500 font-medium text-xs mt-1">ลองปิดตัวกรอง “เฉพาะรอบที่ยังว่าง”</p>
                    </div>

                    <div v-else class="space-y-2 max-h-[420px] overflow-y-auto custom-scrollbar pr-1">
                    <button
                      v-for="s in showAllSchedules ? filteredSchedules : filteredSchedules.slice(0, 5)"
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
                    </div>

                    <button v-if="filteredSchedules.length > 5"
                      @click="showAllSchedules = !showAllSchedules"
                      class="w-full py-2.5 rounded-[1.25rem] border-2 border-dashed border-gray-200 text-sm font-bold text-gray-500 hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition-all flex items-center justify-center gap-2">
                      <span class="material-symbols-rounded text-[18px]">{{ showAllSchedules ? 'expand_less' : 'expand_more' }}</span>
                      {{ showAllSchedules ? 'แสดงน้อยลง' : `ดูทั้งหมด ${filteredSchedules.length} รอบ` }}
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

                  <div v-else class="space-y-3">
                    <label class="inline-flex items-center gap-2 text-xs font-bold text-[var(--color-text-muted)] cursor-pointer select-none">
                      <input v-model="onlyAvailableSchedules" type="checkbox" class="rounded border-gray-300 text-[var(--color-accent)] focus:ring-[var(--color-accent)]" />
                      แสดงเฉพาะรอบที่ยังว่าง
                    </label>

                    <div v-if="filteredSchedulesForRegion.length === 0" class="bg-[var(--color-sand)] rounded-[1.25rem] p-4 text-center border border-gray-100">
                      <p class="text-[var(--color-text-dark)] font-bold text-sm">ไม่พบรอบที่ตรงเงื่อนไขในภูมิภาคนี้</p>
                      <p class="text-gray-500 font-medium text-xs mt-1">ลองปิดตัวกรอง “เฉพาะรอบที่ยังว่าง”</p>
                    </div>

                    <div v-else class="space-y-2 max-h-[420px] overflow-y-auto custom-scrollbar pr-1">
                    <button
                      v-for="s in showAllSchedules ? filteredSchedulesForRegion : filteredSchedulesForRegion.slice(0, 5)"
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
                    </div>

                    <button v-if="filteredSchedulesForRegion.length > 5"
                      @click="showAllSchedules = !showAllSchedules"
                      class="w-full py-2.5 rounded-[1.25rem] border-2 border-dashed border-gray-200 text-sm font-bold text-gray-500 hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition-all flex items-center justify-center gap-2">
                      <span class="material-symbols-rounded text-[18px]">{{ showAllSchedules ? 'expand_less' : 'expand_more' }}</span>
                      {{ showAllSchedules ? 'แสดงน้อยลง' : `ดูทั้งหมด ${filteredSchedulesForRegion.length} รอบ` }}
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
                    :to="{ path: `/booking/${selectedSchedule.id}`, query: selectedRegion ? { region: selectedRegion } : {} }"
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
                    สามารถปรับเปลี่ยนวันหรือเปลี่ยนผู้เข้าใช้สิทธิ์แทนได้ โดยไม่มีค่าใช้จ่าย หากแจ้งล่วงหน้าภายใน 45 วัน
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
                  <p class="text-xs font-semibold text-[#A87830]/80 mt-0.5 flex items-center gap-1">
                    <span class="material-symbols-rounded text-[13px]">calendar_today</span>
                    ทริปวันที่ {{ formatDate(urgentSchedule.departure_date) }}
                    <template v-if="urgentSchedule.return_date && urgentSchedule.return_date !== urgentSchedule.departure_date">
                      &ndash; {{ formatDate(urgentSchedule.return_date) }}
                    </template>
                  </p>
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

        <!-- Reviews Section (Moved to bottom for Mobile flow) -->
        <section id="reviews" class="mt-16 pt-16 border-t border-gray-200">
          <div class="flex items-center justify-between mb-10">
            <div>
              <h3 class="text-2xl md:text-4xl font-extrabold text-[var(--color-text-dark)] tracking-tight mb-2">รีวิวจากผู้ร่วมทริป</h3>
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

          <div v-if="reviewsLoading" class="flex justify-center py-20">
            <div class="w-12 h-12 border-4 border-gray-200 border-t-[var(--color-accent)] rounded-full animate-spin"></div>
          </div>
          
          <div v-else-if="reviews.length > 0" class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div v-for="review in reviews" :key="review.id" class="bg-white p-6 md:p-8 rounded-[2rem] border border-gray-100 shadow-[0_10px_30px_rgba(0,0,0,0.02)] transition-all hover:shadow-[0_15px_40px_rgba(0,0,0,0.04)] h-full flex flex-col">
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
                <div v-for="(img, idx) in review.images" :key="idx" 
                  class="w-20 h-20 md:w-24 md:h-24 rounded-xl overflow-hidden border border-gray-100 cursor-pointer group relative">
                  <img :src="img" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110" />
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

          <div v-else class="text-center py-20 bg-white/50 rounded-[2.5rem] border border-dashed border-gray-200">
            <div class="w-16 h-16 bg-[var(--color-sand)] rounded-full flex items-center justify-center mx-auto mb-4">
              <span class="material-symbols-rounded text-gray-300 text-3xl">rate_review</span>
            </div>
            <p class="text-[var(--color-text-muted)] font-extrabold text-lg mb-1">ยังไม่มีการรีวิวสำหรับทริปนี้</p>
            <p class="text-[var(--color-text-muted)] text-sm font-medium">ร่วมแชร์ประสบการณ์การเดินทางของคุณได้ หลังจากจบทริป</p>
          </div>
        </section>
      </div>
    </div>

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
        <div v-if="showAvailabilityModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
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
              <div v-if="schedules.length > 0" class="space-y-4">
                <div v-for="s in schedules" :key="s.id" 
                  class="bg-white p-4 md:p-5 rounded-2xl border border-gray-100 shadow-sm flex flex-col sm:flex-row sm:items-center justify-between gap-4 transition-all hover:border-[var(--color-accent)]/30 hover:shadow-md"
                  :class="{'opacity-60': Number(s.available_seats) === 0}">
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
                          <span class="w-2 h-2 rounded-full" :class="Number(s.available_seats) > 0 ? 'bg-green-500 animate-pulse' : 'bg-red-500'"></span>
                          <span class="text-xs md:text-sm font-bold" :class="Number(s.available_seats) > 0 ? 'text-[var(--color-accent)]' : 'text-red-500'">
                            {{ Number(s.available_seats) > 0 ? `ว่าง ${s.available_seats} ที่นั่ง` : 'เต็มแล้ว' }}
                          </span>
                        </div>
                        <span class="w-1 h-1 rounded-full bg-gray-300"></span>
                        <span class="text-xs md:text-sm font-black text-[var(--color-text-dark)]">฿{{ Number(s.price || trip.price_per_person).toLocaleString() }}</span>
                      </div>
                    </div>
                  </div>
                  
                  <router-link 
                    v-if="Number(s.available_seats) > 0"
                    :to="`/booking/${s.id}`"
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
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';
import api from '../lib/axios';
import { useHead } from '@unhead/vue';

const route = useRoute();
const trip = ref(null);

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
    { property: 'og:image', content: computed(() => trip.value?.cover_image ? (trip.value.cover_image.startsWith('http') ? trip.value.cover_image : `${window.location.origin}${trip.value.cover_image}`) : `${window.location.origin}/images/logo.png`) },
    { property: 'og:image:alt', content: computed(() => trip.value ? `${trip.value.title} - ลุยเลเขา` : '') },
    { property: 'og:image:width', content: '1200' },
    { property: 'og:image:height', content: '630' },
    { property: 'product:price:amount', content: computed(() => trip.value?.price_per_person?.toString() || '') },
    { property: 'product:price:currency', content: 'THB' },

    // Twitter Card
    { name: 'twitter:card', content: 'summary_large_image' },
    { name: 'twitter:title', content: computed(() => trip.value ? `${trip.value.title} | ลุยเลเขา` : '') },
    { name: 'twitter:description', content: computed(() => trip.value ? (trip.value.description || '').substring(0, 200) : '') },
    { name: 'twitter:image', content: computed(() => trip.value?.cover_image ? (trip.value.cover_image.startsWith('http') ? trip.value.cover_image : `${window.location.origin}${trip.value.cover_image}`) : `${window.location.origin}/images/logo.png`) },
    { name: 'twitter:image:alt', content: computed(() => trip.value ? trip.value.title : 'ลุยเลเขา') }
  ],
  // JSON-LD Structured Data for Trip (Product + TouristTrip)
  script: [
    {
      type: 'application/ld+json',
      innerHTML: computed(() => {
        if (!trip.value) return '{}';
        const t = trip.value;
        const imageUrl = t.cover_image ? (t.cover_image.startsWith('http') ? t.cover_image : `${window.location.origin}${t.cover_image}`) : `${window.location.origin}/images/logo.png`;
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
    }
  ]
});

const schedules = ref([]);
const showAllSchedules = ref(false);
const selectedSchedule = ref(null);
const activeIconPicker = ref(null);
const openDays = ref([0]); // Default open Day 0

const toggleDay = (day) => {
  if (openDays.value.includes(day)) {
    openDays.value = openDays.value.filter(d => d !== day);
  } else {
    openDays.value.push(day);
  }
};
const selectedPickup = ref(null);
const selectedRegion = ref(null);
const onlyAvailableSchedules = ref(true);
const loading = ref(true);
const schedulesLoading = ref(false);
const reviews = ref([]);
const reviewsLoading = ref(false);
const showMustKnowModal = ref(false);
const distanceLoading = ref(false);
const distanceData = ref([]);
const showGalleryModal = ref(false);
const activeGalleryIndex = ref(0);
const showAvailabilityModal = ref(false);

const isTrekking = computed(() => trip.value?.type === 'trekking');

const allPickupPoints = computed(() => {
  if (!isTrekking.value) return [];
  const pts = selectedSchedule.value?.pickup_points
    || schedules.value[0]?.pickup_points
    || [];
  return pts;
});

const groupedPickupPointsByRegion = computed(() => {
  if (!allPickupPoints.value.length) return [];

  const regionOrder = ['bangkok', 'central', 'north', 'northeast', 'east', 'west', 'south'];
  const regionOrderMap = new Map(regionOrder.map((name, index) => [name, index]));
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
      points: [...group.points].sort((a, b) => Number(a.price || 0) - Number(b.price || 0)),
    }))
    .sort((a, b) => {
      const aOrder = regionOrderMap.has(a.region) ? regionOrderMap.get(a.region) : Number.MAX_SAFE_INTEGER;
      const bOrder = regionOrderMap.has(b.region) ? regionOrderMap.get(b.region) : Number.MAX_SAFE_INTEGER;
      if (aOrder !== bOrder) return aOrder - bOrder;
      return a.region_label.localeCompare(b.region_label, 'th');
    });
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

const filteredSchedules = computed(() => {
  if (!onlyAvailableSchedules.value) return schedules.value;
  return schedules.value.filter(s => Number(s.available_seats) > 0);
});

const filteredSchedulesForRegion = computed(() => {
  if (!onlyAvailableSchedules.value) return schedulesForRegion.value;
  return schedulesForRegion.value.filter(s => Number(s.available_seats) > 0);
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

const urgentSchedule = computed(() => {
  if (!schedules.value.length) return null;
  const s = schedules.value.reduce((a, b) => a.available_seats < b.available_seats ? a : b);
  return s.available_seats > 0 && s.available_seats <= 5 ? s : null;
});

const urgentSeats = computed(() => urgentSchedule.value?.available_seats ?? 0);

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
    window.addEventListener('keydown', handleKeyDown);
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
    schedulesLoading.value = false;
  }
});

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeyDown);
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
