<template>
  <div class="min-h-screen bg-gray-50/50">

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center min-h-screen">
      <div class="flex flex-col items-center gap-4">
        <div class="w-12 h-12 rounded-full border-4 border-teal-100 border-t-teal-600 animate-spin"></div>
        <p class="text-sm text-gray-500 font-medium animate-pulse">กำลังโหลดข้อมูล...</p>
      </div>
    </div>

    <div v-else-if="schedule" class="pt-6 pb-24 px-4 md:px-8 max-w-screen-xl mx-auto">

      <!-- Breadcrumb -->
      <nav class="text-sm text-gray-500 mb-6 flex items-center gap-2 bg-white px-4 py-3 rounded-2xl shadow-sm border border-gray-100 w-fit">
        <router-link to="/trips" class="hover:text-teal-600 transition-colors flex items-center gap-1.5 font-medium">
          <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 0,'wght' 400">explore</span>
          กิจกรรม
        </router-link>
        <span class="material-symbols-rounded text-gray-300 text-[20px]">chevron_right</span>
        <span class="text-gray-700 font-bold bg-gray-100 px-3 py-1 rounded-full">จองกิจกรรม</span>
      </nav>

      <!-- Title -->
      <div class="mb-10 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100 relative overflow-hidden">
        <h1 class="font-anuphan text-2xl md:text-4xl font-extrabold text-gray-900 mb-4 relative z-10">
          {{ schedule.trip?.title }}
        </h1>
        <div class="flex flex-wrap gap-4 text-sm font-medium relative z-10">
          <div class="flex items-center gap-2 bg-gray-50 px-4 py-2 rounded-xl text-gray-700 border border-gray-200/60">
            <span class="material-symbols-rounded text-teal-600 text-[20px]" style="font-variation-settings:'FILL' 1,'wght' 400">calendar_month</span>
            <span>
              {{ formatDate(schedule.departure_date) }}
              <template v-if="schedule.return_date !== schedule.departure_date"> — {{ formatDate(schedule.return_date) }}</template>
            </span>
          </div>
          <div class="flex items-center gap-2 bg-gray-50 px-4 py-2 rounded-xl text-gray-700 border border-gray-200">
            <span class="material-symbols-rounded text-teal-600 text-[20px]" style="font-variation-settings:'FILL' 1,'wght' 400">event_seat</span>
            <span>ว่าง {{ schedule.available_seats }}/{{ schedule.total_seats }} ที่นั่ง</span>
          </div>
          <div v-if="schedule.trip?.is_women_only" class="flex items-center gap-2 bg-pink-50 px-4 py-2 rounded-xl text-pink-700 border border-pink-200 animate-pulse">
            <span class="material-symbols-rounded text-pink-600 text-[20px]" style="font-variation-settings:'FILL' 1,'wght' 400">female</span>
            <span class="font-bold">ทริปสำหรับผู้หญิงเท่านั้น</span>
          </div>
        </div>
      </div>

      <!-- Progress Stepper -->
      <div class="flex justify-center mb-12">
        <div class="flex items-center w-full max-w-3xl relative">
          <template v-for="(s, i) in steps" :key="i">
            <div class="flex flex-col items-center flex-1 relative z-10">
              <div class="w-12 h-12 rounded-2xl flex items-center justify-center mb-3 transition-all duration-300 shadow-sm"
                :class="step > i
                  ? 'bg-teal-600 text-white shadow-teal-600/20'
                  : step === i
                    ? 'bg-teal-700 text-white ring-4 ring-teal-600/20 scale-110 shadow-teal-700/30'
                    : 'bg-white border-2 border-gray-200 text-gray-400'">
                <span v-if="step > i" class="material-symbols-rounded text-[22px]" style="font-variation-settings:'FILL' 1,'wght' 400">check_circle</span>
                <span v-else class="text-base font-bold">{{ i + 1 }}</span>
              </div>
              <span class="text-sm font-medium text-center transition-colors"
                :class="step === i ? 'text-teal-700 font-bold' : step > i ? 'text-teal-600' : 'text-gray-400'">
                {{ s }}
              </span>
            </div>
            <div v-if="i < steps.length - 1" class="h-1 flex-1 mx-2 mb-8 transition-colors duration-300 rounded-full"
              :class="step > i ? 'bg-teal-600' : 'bg-gray-200'"></div>
          </template>
        </div>
      </div>

      <!-- Step: Region Picker (Trekking only, before seat map / passenger info) -->
      <div v-if="isTrekking && step === 0" class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        <div class="lg:col-span-7 xl:col-span-8"> 
          <div class="mb-8 bg-white p-6 rounded-3xl shadow-sm border border-gray-100">
            <h2 class="text-2xl font-bold text-gray-900 mb-2">เลือกภูมิภาคของคุณ</h2>
            <p class="text-gray-500">เลือกภูมิภาคที่คุณต้องการขึ้นรถ ราคาและจุดนัดหมายอาจแตกต่างกันในแต่ละภูมิภาค</p>
          </div>

          <div v-if="!pickupPoints.length" class="flex flex-col items-center justify-center py-16 text-gray-400 bg-white rounded-3xl shadow-sm border border-gray-100">
            <span class="material-symbols-rounded text-6xl mb-4 text-gray-300" style="font-variation-settings:'FILL' 0,'wght' 300">map</span>
            <p class="text-base font-medium">ทริปนี้ยังไม่ได้กำหนดจุดรับผู้โดยสาร</p>
            <button @click="skipRegionStep" class="mt-4 text-sm text-teal-600 hover:text-teal-700 font-medium underline underline-offset-4">ดำเนินการต่อโดยไม่เลือกภูมิภาค</button>
          </div>

          <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <button
              v-for="pt in pickupPoints" :key="pt.id"
              @click="selectRegion(pt)"
              class="group text-left p-6 rounded-3xl border-2 transition-all duration-300 relative overflow-hidden"
              :class="selectedPickup?.id === pt.id
                ? 'border-teal-500 bg-gray-50 shadow-md shadow-teal-500/10'
                : 'border-gray-200 bg-white hover:border-teal-500/30 hover:shadow-sm'">
              
              <div class="flex items-start justify-between mb-4 relative z-10">
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold transition-colors"
                  :class="selectedPickup?.id === pt.id ? 'bg-teal-600 text-white shadow-sm' : 'bg-gray-100 text-gray-600 group-hover:bg-gray-200'">
                  <span class="material-symbols-rounded text-[16px]" style="font-variation-settings:'FILL' 1,'wght' 400">location_on</span>
                  {{ pt.region_label }}
                </span>
                <span class="font-anuphan text-2xl font-extrabold transition-colors"
                  :class="selectedPickup?.id === pt.id ? 'text-teal-700' : 'text-gray-900'">
                  ฿{{ Number(pt.price).toLocaleString() }}
                </span>
              </div>
              <p class="text-sm font-medium text-gray-900 mb-2 flex items-start gap-2 relative z-10">
                <span class="material-symbols-rounded text-[18px] text-red-500 shrink-0 mt-0.5" style="font-variation-settings:'FILL' 1,'wght' 400">place</span>
                {{ pt.pickup_location }}
              </p>
              <p v-if="pt.notes" class="text-xs text-gray-500 flex items-start gap-2 mt-3 bg-white/60 p-2.5 rounded-xl border border-gray-100 relative z-10">
                <span class="material-symbols-rounded text-[16px] text-amber-500 shrink-0" style="font-variation-settings:'FILL' 0,'wght' 400">schedule</span>
                {{ pt.notes }}
              </p>
              <div class="flex items-center justify-between mt-4 relative z-10">
                <a v-if="pt.map_url" :href="pt.map_url" target="_blank"
                  @click.stop
                  class="inline-flex items-center gap-1.5 text-xs font-medium text-teal-600 hover:text-teal-700 bg-gray-100 hover:bg-gray-200 px-3 py-1.5 rounded-lg transition-colors">
                  <span class="material-symbols-rounded text-[16px]" style="font-variation-settings:'FILL' 0,'wght' 400">open_in_new</span>
                  ดูแผนที่
                </a>
                <div v-if="selectedPickup?.id === pt.id"
                  class="flex items-center gap-1.5 text-sm font-bold text-teal-600 bg-gray-100 px-3 py-1.5 rounded-lg">
                  <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 1,'wght' 400">check_circle</span>
                  เลือกแล้ว
                </div>
              </div>
            </button>
          </div>

          <div class="mt-8 flex justify-end">
            <button
              @click="confirmRegion"
              :disabled="!selectedPickup && pickupPoints.length > 0"
              class="w-full sm:w-auto bg-teal-600 text-white px-8 py-4 rounded-2xl font-bold text-base hover:bg-teal-700 active:scale-95 transition-all duration-300 shadow-lg shadow-teal-600/20 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-teal-600 flex items-center justify-center gap-2">
              <span>{{ pickupPoints.length ? 'ยืนยันภูมิภาคและดำเนินการต่อ' : 'ดำเนินการต่อ' }}</span>
              <span class="material-symbols-rounded" style="font-variation-settings:'FILL' 0,'wght' 400">arrow_forward</span>
            </button>
          </div>
        </div>

        <!-- Sidebar region summary -->
        <aside class="lg:col-span-5 xl:col-span-4 sticky top-8">
          <div class="bg-white rounded-3xl p-6 shadow-sm border border-gray-100">
            <h2 class="text-lg font-bold mb-5 flex items-center gap-2 text-gray-900">
              <span class="material-symbols-rounded text-teal-600" style="font-variation-settings:'FILL' 1,'wght' 400">receipt_long</span>
              สรุปรายการจอง
            </h2>
            
            <div v-if="selectedPickup" class="mb-5 p-5 rounded-2xl bg-gray-50 border border-gray-200 shadow-sm">
              <div class="flex items-center justify-between mb-3">
                <p class="text-xs font-bold text-teal-700 uppercase tracking-wider bg-gray-200 px-2 py-1 rounded-md">ภูมิภาคที่เลือก</p>
                <span class="material-symbols-rounded text-teal-500 text-[20px]" style="font-variation-settings:'FILL' 1,'wght' 400">check_circle</span>
              </div>
              <p class="font-bold text-gray-900 text-lg mb-2">{{ selectedPickup.region_label }}</p>
              <p class="text-sm text-gray-600 flex items-start gap-1.5">
                <span class="material-symbols-rounded text-[18px] text-red-500 shrink-0 mt-0.5" style="font-variation-settings:'FILL' 1,'wght' 400">place</span>
                {{ selectedPickup.pickup_location }}
              </p>
            </div>

            <div class="space-y-4 bg-gray-50 rounded-2xl p-5 border border-gray-100">
              <div class="flex justify-between items-center text-sm font-medium">
                <span class="text-gray-500">ราคา</span>
                <span class="text-gray-900">฿{{ selectedPickup ? Number(selectedPickup.price).toLocaleString() : Number(schedule.price).toLocaleString() }}</span>
              </div>
              <div class="pt-4 border-t border-dashed border-gray-300 flex justify-between items-end">
                <span class="text-sm font-bold text-gray-500">ราคาเริ่มต้น</span>
                <span class="text-3xl font-extrabold text-teal-700 font-anuphan tracking-tight">
                  <span class="text-lg text-teal-600 mr-1">฿</span>{{ (selectedPickup ? Number(selectedPickup.price) : Number(schedule.price)).toLocaleString() }}
                </span>
              </div>
            </div>
          </div>
        </aside>
      </div>

      <!-- Main Content Grid -->
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">

        <!-- Left: Main Steps -->
        <div class="lg:col-span-7 xl:col-span-8" v-if="!isTrekking || step > 0">

          <!-- Step 0: Seat Map -->
          <div v-if="step === (isTrekking ? 1 : 0) && hasSeatMap">
            <div class="mb-8 bg-white p-6 rounded-3xl shadow-sm border border-gray-100">
              <h2 class="text-2xl font-bold text-gray-900 mb-2">เลือกที่นั่งของคุณ</h2>
              <p class="text-gray-500">สัมผัสความสบายระดับพรีเมียมในทุกการเดินทาง</p>
            </div>

            <SeatMap :seat-map="seatsStore.seatMap" :is-women-only="schedule.trip?.is_women_only" />

            <p v-if="seatError" class="flex items-center gap-2 text-red-600 text-sm mt-6 p-4 bg-red-50 border border-red-100 rounded-2xl">
              <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 1,'wght' 400">error</span>
              {{ seatError }}
            </p>
          </div>

          <!-- Passenger Info step -->
          <div v-if="(step === (isTrekking ? 1 : 0) && !hasSeatMap) || step === (isTrekking ? 2 : 1)">
            <div class="mb-8 bg-white p-6 rounded-3xl shadow-sm border border-gray-100">
              <h2 class="text-2xl font-bold text-gray-900 mb-2">ข้อมูลผู้โดยสาร</h2>
              <p class="text-gray-500">กรุณากรอกข้อมูลให้ครบถ้วนเพื่อความปลอดภัยในการเดินทาง</p>
            </div>

            <!-- Countdown -->
            <CountdownTimer v-if="seatsStore.countdownSeconds > 0"
              :seconds="seatsStore.countdownSeconds" class="mb-6" />

            <!-- Group Booking Toggle -->
            <div class="mb-6 p-6 bg-white rounded-3xl border border-gray-100 shadow-sm transition-all">
              <label class="flex items-center gap-4 cursor-pointer group">
                <div class="relative flex items-center justify-center">
                  <input type="checkbox" v-model="isGroup"
                    class="peer appearance-none w-6 h-6 rounded-lg border-2 border-gray-300 checked:bg-teal-600 checked:border-teal-600 focus:ring-4 focus:ring-teal-600/20 transition-all cursor-pointer" />
                  <span class="material-symbols-rounded absolute text-white opacity-0 peer-checked:opacity-100 pointer-events-none text-[18px] transition-opacity" style="font-variation-settings:'FILL' 1,'wght' 600">check</span>
                </div>
                <div>
                  <span class="flex items-center gap-2 text-base font-bold text-gray-900 group-hover:text-teal-700 transition-colors">
                    <span class="material-symbols-rounded text-teal-600" style="font-variation-settings:'FILL' 0,'wght' 400">groups</span>
                    การจองกลุ่ม
                  </span>
                  <span class="text-sm text-gray-500">จองสำหรับหลายผู้โดยสารในการจองเดียว</span>
                </div>
              </label>
              
              <div v-if="isGroup" class="mt-6 pt-6 border-t border-gray-100 grid grid-cols-1 md:grid-cols-2 gap-5 animate-in fade-in slide-in-from-top-2 duration-300">
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">ชื่อกลุ่ม</label>
                  <input v-model="groupName" type="text" placeholder="เช่น ทีมบริษัท ABC"
                    class="w-full border-2 border-gray-200 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white" />
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">หมายเหตุกลุ่ม</label>
                  <input v-model="groupNotes" type="text" placeholder="ข้อมูลเพิ่มเติมสำหรับกลุ่ม"
                    class="w-full border-2 border-gray-200 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white" />
                </div>
              </div>
            </div>

            <!-- Number of passengers -->
            <div class="mb-8 p-6 bg-white rounded-3xl border border-gray-100 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
              <label class="flex items-center gap-2 text-base font-bold text-gray-900">
                <span class="material-symbols-rounded text-teal-600" style="font-variation-settings:'FILL' 0,'wght' 400">group</span>
                จำนวนผู้โดยสาร
              </label>
              <!-- Locked to seat selection -->
              <div v-if="hasSeatMap" class="flex items-center gap-3">
                <span class="text-sm text-gray-500 bg-gray-50 px-3 py-1.5 rounded-xl border border-gray-100 hidden md:inline-block">กำหนดตามที่นั่งที่เลือก ({{ seatsStore.selectedSeatIds.join(', ') }})</span>
                <div class="inline-flex items-center gap-2 border-2 border-gray-200 rounded-2xl px-5 py-3 text-base bg-gray-50 text-gray-700 font-bold shadow-sm">
                  <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 1,'wght' 400">event_seat</span>
                  {{ seatsStore.selectedSeats.length }} คน
                </div>
              </div>
              <!-- Free selection -->
              <div v-else class="relative">
                <select v-model="passengerCount"
                  class="w-full md:w-48 appearance-none border-2 border-gray-200 rounded-2xl px-5 py-3 text-base font-bold bg-white text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all cursor-pointer">
                  <option v-for="n in maxPassengers" :key="n" :value="n">{{ n }} คน</option>
                </select>
                <span class="material-symbols-rounded absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">expand_more</span>
              </div>
            </div>

            <!-- Passenger forms -->
            <div v-for="(p, i) in passengers" :key="i"
              class="bg-white border border-gray-100 rounded-3xl p-6 md:p-8 mb-6 shadow-sm relative overflow-hidden transition-all hover:shadow-md hover:border-gray-200">
              
              <div class="absolute top-0 left-0 w-2 h-full bg-teal-600 rounded-l-3xl"></div>
              
              <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <h3 class="flex items-center gap-3 font-bold text-gray-900 text-lg">
                  <span class="w-10 h-10 rounded-2xl bg-gray-100 text-gray-700 flex items-center justify-center text-base font-bold shadow-sm border border-gray-200">{{ i + 1 }}</span>
                  ผู้โดยสารคนที่ {{ i + 1 }}
                </h3>
                <div class="flex flex-wrap items-center gap-3">
                  <button v-if="i === 0 && authStore.isLoggedIn" type="button" @click="autoFillFromProfile(i)"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-gray-100 text-teal-700 text-sm font-bold border border-gray-200 hover:bg-teal-50 hover:border-teal-100 transition-all active:scale-95 shadow-sm">
                    <span class="material-symbols-rounded text-[18px]">account_circle</span>
                    ดึงข้อมูลจากโปรไฟล์
                  </button>
                  <div v-if="hasSeatMap && seatsStore.selectedSeats[i]"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-teal-600 text-white text-sm font-bold shadow-sm shadow-teal-600/20 w-fit">
                    <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 1,'wght' 400">airline_seat_recline_extra</span>
                    ที่นั่ง {{ seatsStore.selectedSeats[i].id }}
                  </div>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="md:col-span-2 grid grid-cols-12 gap-5">
                  <div class="col-span-12 md:col-span-3">
                    <label class="block text-sm font-bold text-gray-700 mb-2">คำนำหน้า <span class="text-red-500">*</span></label>
                    <select v-model="p.title" required
                      class="w-full border-2 border-gray-200 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all bg-gray-50/50 hover:bg-gray-50 focus:bg-white">
                      <option value="" disabled>เลือก...</option>
                      <option v-if="!schedule.trip?.is_women_only" value="นาย">นาย</option>
                      <option value="นาง">นาง</option>
                      <option value="นางสาว">นางสาว</option>
                    </select>
                  </div>
                  <div class="col-span-12 md:col-span-9">
                    <label class="block text-sm font-bold text-gray-700 mb-2">ชื่อ-นามสกุล <span class="text-red-500">*</span></label>
                    <input v-model="p.name" type="text" required placeholder="กรอกชื่อ-นามสกุล"
                      class="w-full border-2 border-gray-200 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white" />
                  </div>
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">ชื่อเล่น</label>
                  <input v-model="p.nickname" type="text" placeholder="กรอกชื่อเล่น"
                    class="w-full border-2 border-gray-200 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white" />
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2 flex items-center justify-between">
                    <span>เลขที่บัตรประชาชน (สำหรับประกัน) <span class="text-red-500">*</span></span>
                    <button type="button" @click="showInsuranceModal = true" class="text-teal-600 hover:text-teal-700 flex items-center gap-1 text-[11px] font-bold bg-teal-50 px-2 py-1 rounded-lg border border-teal-100 transition-all active:scale-95">
                      <span class="material-symbols-rounded text-[14px]">info</span>
                      รายละเอียดประกัน
                    </button>
                  </label>
                  <input v-model="p.id_card" type="text" required placeholder="เลขบัตรประชาชน 13 หลัก"
                    maxlength="13"
                    @input="p.id_card = p.id_card.replace(/\D/g, '')"
                    class="w-full border-2 border-gray-200 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white" />
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">เบอร์โทรศัพท์ <span class="text-red-500">*</span></label>
                  <input v-model="p.phone" type="tel" placeholder="0XX-XXX-XXXX"
                    class="w-full border-2 border-gray-200 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white" />
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">กรุ๊ปเลือด</label>
                  <select v-model="p.blood_group"
                    class="w-full border-2 border-gray-200 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all bg-gray-50/50 hover:bg-gray-50 focus:bg-white">
                    <option value="">ไม่ระบุ</option>
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="O">O</option>
                    <option value="AB">AB</option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">ผู้ติดต่อฉุกเฉิน <span class="text-red-500">*</span></label>
                  <input v-model="p.emergency_contact" type="text" placeholder="ชื่อผู้ติดต่อ"
                    class="w-full border-2 border-gray-200 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white" />
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">เบอร์ฉุกเฉิน <span class="text-red-500">*</span></label>
                  <input v-model="p.emergency_phone" type="tel" placeholder="0XX-XXX-XXXX"
                    class="w-full border-2 border-gray-200 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white" />
                </div>
                <div class="md:col-span-2">
                  <label class="block text-sm font-bold text-gray-700 mb-2">การแพ้อาหาร / อื่นๆ</label>
                  <input v-model="p.allergies" type="text" placeholder="เช่น แพ้อาหารทะเล, ไม่ทานเนื้อ"
                    class="w-full border-2 border-gray-200 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white" />
                </div>

                <div class="md:col-span-2">
                  <label class="block text-sm font-bold text-gray-700 mb-2">หมายเหตุสุขภาพ (ถ้ามี)</label>
                  <textarea v-model="p.health_notes" rows="2" placeholder="แพ้ยา, โรคประจำตัว ฯลฯ"
                    class="w-full border-2 border-gray-200 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white resize-none"></textarea>
                </div>
              </div>
            </div>

            <div class="mt-8 flex justify-end">
              <button @click="goToSummary"
                :disabled="!isPassengerValid"
                class="w-full sm:w-auto bg-teal-600 text-white px-8 py-4 rounded-2xl font-bold text-base hover:bg-teal-700 active:scale-95 transition-all duration-300 shadow-lg shadow-teal-600/20 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-teal-600 flex items-center justify-center gap-2">
                <span>ดูสรุปการจอง</span>
                <span class="material-symbols-rounded" style="font-variation-settings:'FILL' 0,'wght' 400">arrow_forward</span>
              </button>
            </div>
          </div>

          <!-- Step 2/3: Summary -->
          <div v-if="step === (isTrekking ? 3 : 2)">
            <div class="mb-8 bg-white p-6 rounded-3xl shadow-sm border border-gray-100">
              <h2 class="text-2xl font-bold text-gray-900 mb-2">สรุปการจอง</h2>
              <p class="text-gray-500">ตรวจสอบข้อมูลก่อนยืนยันการจอง</p>
            </div>

            <CountdownTimer v-if="seatsStore.countdownSeconds > 0"
              :seconds="seatsStore.countdownSeconds" class="mb-6" />

            <!-- Trip Summary Card -->
            <div class="bg-white border border-gray-100 rounded-3xl p-6 md:p-8 mb-6 shadow-sm relative overflow-hidden">
              <h3 class="font-bold text-gray-900 text-xl mb-6 flex items-center gap-3 relative z-10">
                <div class="w-10 h-10 rounded-2xl bg-gray-100 flex items-center justify-center border border-gray-200 shadow-sm">
                  <span class="material-symbols-rounded text-teal-600" style="font-variation-settings:'FILL' 0,'wght' 400">confirmation_number</span>
                </div>
                {{ schedule.trip?.title }}
              </h3>
              
              <div class="space-y-4 text-sm relative z-10">
                <div class="flex justify-between items-center py-3 border-b border-gray-100">
                  <span class="flex items-center gap-2 text-gray-500 font-medium">
                    <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 0,'wght' 400">calendar_month</span>
                    วันเดินทาง
                  </span>
                  <span class="font-bold text-gray-900">{{ formatDate(schedule.departure_date) }}</span>
                </div>
                <div class="flex justify-between items-center py-3 border-b border-gray-100">
                  <span class="flex items-center gap-2 text-gray-500 font-medium">
                    <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 0,'wght' 400">group</span>
                    จำนวนผู้โดยสาร
                  </span>
                  <span class="font-bold text-gray-900">{{ passengers.length }} คน</span>
                </div>
                <div v-if="hasSeatMap" class="flex justify-between items-center py-3 border-b border-gray-100">
                  <span class="flex items-center gap-2 text-gray-500 font-medium">
                    <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 0,'wght' 400">event_seat</span>
                    ที่นั่ง
                  </span>
                  <span class="font-bold text-teal-700 bg-gray-100 px-3 py-1 rounded-lg">{{ seatsStore.selectedSeatIds.join(', ') }}</span>
                </div>
                <div v-if="selectedPickup" class="flex justify-between items-center py-3 border-b border-gray-100">
                  <span class="flex items-center gap-2 text-gray-500 font-medium">
                    <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 0,'wght' 400">location_on</span>
                    จุดขึ้นรถ
                  </span>
                  <span class="font-bold text-gray-900">{{ selectedPickup.region_label }}</span>
                </div>
                <div class="flex justify-between items-center py-3 border-b border-gray-100">
                  <span class="flex items-center gap-2 text-gray-500 font-medium">
                    <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 0,'wght' 400">sell</span>
                    ราคาต่อคน
                  </span>
                  <span class="font-bold text-gray-900">฿{{ Number(effectivePrice).toLocaleString() }}</span>
                </div>
                
                <div class="flex justify-between items-end pt-6 mt-2">
                  <span class="font-bold text-gray-900 text-base">ยอดรวมทั้งหมด</span>
                  <span class="font-anuphan text-3xl font-extrabold text-teal-700 tracking-tight">
                    <span class="text-xl text-teal-600 mr-1">฿</span>{{ totalAmount.toLocaleString() }}
                  </span>
                </div>
              </div>
            </div>

            <!-- Passenger summary -->
            <div class="bg-white border border-gray-100 rounded-3xl p-6 md:p-8 mb-8 shadow-sm">
              <h3 class="font-bold text-gray-900 text-lg mb-6 flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-gray-100 flex items-center justify-center border border-gray-200 shadow-sm">
                  <span class="material-symbols-rounded text-teal-600" style="font-variation-settings:'FILL' 0,'wght' 400">people</span>
                </div>
                ข้อมูลผู้โดยสาร
              </h3>
              
              <div class="space-y-4">
                <div v-for="(p, i) in passengers" :key="i"
                  class="flex items-center gap-4 p-4 rounded-2xl bg-gray-50/50 border border-gray-100 hover:bg-gray-50 transition-colors">
                  <span class="w-10 h-10 rounded-xl bg-teal-600 text-white flex items-center justify-center text-sm font-bold shadow-sm shadow-teal-600/20 shrink-0">
                    {{ i + 1 }}
                  </span>
                  <div class="flex-1 min-w-0">
                    <p class="font-bold text-gray-900 text-sm truncate">{{ p.name }} <span v-if="p.nickname" class="text-teal-600 font-bold ml-1">({{ p.nickname }})</span></p>
                    <p v-if="p.phone" class="text-xs text-gray-500 mt-0.5 flex items-center gap-1 font-medium">
                      <span class="material-symbols-rounded text-[14px]">call</span>
                      {{ p.phone }}
                    </p>
                    <div class="flex flex-wrap gap-2 mt-1">
                      <span v-if="p.id_card" class="text-[10px] bg-gray-100 text-gray-600 px-2 py-0.5 rounded border border-gray-200">ID: {{ p.id_card }}</span>
                      <span v-if="p.blood_group" class="text-[10px] bg-red-50 text-red-600 px-2 py-0.5 rounded border border-red-100">Blood: {{ p.blood_group }}</span>
                    </div>
                  </div>
                  <div v-if="hasSeatMap && seatsStore.selectedSeats[i]"
                    class="px-3 py-1.5 rounded-xl bg-gray-100 text-gray-700 text-sm font-bold border border-gray-200 shrink-0 flex items-center gap-1.5">
                    <span class="material-symbols-rounded text-[16px]">airline_seat_recline_extra</span>
                    {{ seatsStore.selectedSeats[i].id }}
                  </div>
                </div>
              </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-4">
              <button @click="step = hasSeatMap ? (isTrekking ? 2 : 1) : (isTrekking ? 1 : 0)"
                class="flex items-center justify-center gap-2 bg-white border-2 border-gray-200 text-gray-700 px-8 py-4 rounded-2xl font-bold text-base hover:bg-gray-50 hover:border-gray-300 active:scale-95 transition-all duration-300">
                <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">arrow_back</span>
                ย้อนกลับ
              </button>
              <button @click="createBooking"
                :disabled="bookingLoading"
                class="flex-1 flex items-center justify-center gap-2 bg-teal-600 text-white px-8 py-4 rounded-2xl font-bold text-base hover:bg-teal-700 active:scale-95 transition-all duration-300 shadow-lg shadow-teal-600/20 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-teal-600">
                <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">payment</span>
                <span>{{ bookingLoading ? 'กำลังสร้างการจอง...' : 'ยืนยันและชำระเงิน' }}</span>
              </button>
            </div>
            <p v-if="bookingError" class="flex items-center gap-2 text-red-600 text-sm mt-6 p-4 bg-red-50 border border-red-100 rounded-2xl">
              <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 1,'wght' 400">error</span>
              {{ bookingError }}
            </p>
          </div>
        </div>

        <!-- Right: Booking Panel (Sidebar) -->
        <aside class="lg:col-span-5 xl:col-span-4 sticky top-8 space-y-6" v-if="!isTrekking || step > 0">

          <!-- Summary Card -->
          <div class="bg-white rounded-3xl p-6 shadow-sm border border-gray-100">
            <h2 class="text-lg font-bold mb-5 flex items-center gap-2 text-gray-900">
              <span class="material-symbols-rounded text-teal-600" style="font-variation-settings:'FILL' 1,'wght' 400">receipt_long</span>
              สรุปรายการจอง
            </h2>

            <!-- Selected seats (when on step 0 seatmap) -->
            <div v-if="hasSeatMap && seatsStore.hasSelectedSeats" class="mb-5">
              <div class="flex justify-between items-center p-5 rounded-2xl bg-gray-50 border border-gray-200 shadow-sm">
                <div>
                  <p class="text-xs font-bold text-teal-700 uppercase tracking-wider mb-1 bg-gray-200 px-2 py-1 rounded-md inline-block">ที่นั่งที่เลือก</p>
                  <p class="text-xl font-bold text-gray-900 mt-1">{{ seatsStore.selectedSeatIds.join(', ') }}</p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-teal-600 flex items-center justify-center text-white shadow-sm shadow-teal-600/20">
                  <span class="material-symbols-rounded text-[24px]" style="font-variation-settings:'FILL' 1,'wght' 400">event_seat</span>
                </div>
              </div>
            </div>

            <!-- Selected region info -->
            <div v-if="selectedPickup" class="mb-5 p-5 rounded-2xl bg-gray-50 border border-gray-200 shadow-sm">
              <div class="flex items-center justify-between mb-3">
                <p class="text-xs font-bold text-teal-700 uppercase tracking-wider bg-gray-200 px-2 py-1 rounded-md">จุดขึ้นรถ</p>
                <span class="material-symbols-rounded text-teal-500 text-[20px]" style="font-variation-settings:'FILL' 1,'wght' 400">check_circle</span>
              </div>
              <p class="font-bold text-gray-900 mb-2">{{ selectedPickup.region_label }}</p>
              <p class="text-sm text-gray-600 flex items-start gap-1.5">
                <span class="material-symbols-rounded text-[18px] text-red-500 shrink-0 mt-0.5" style="font-variation-settings:'FILL' 1,'wght' 400">place</span>
                {{ selectedPickup.pickup_location }}
              </p>
              <a v-if="selectedPickup.map_url" :href="selectedPickup.map_url" target="_blank"
                class="mt-3 inline-flex items-center gap-1.5 text-xs font-medium text-teal-600 hover:text-teal-700 bg-gray-100 hover:bg-gray-200 px-3 py-1.5 rounded-lg transition-colors">
                <span class="material-symbols-rounded text-[16px]" style="font-variation-settings:'FILL' 0,'wght' 400">open_in_new</span>
                ดูแผนที่
              </a>
            </div>

            <div class="space-y-4 mb-6 bg-gray-50 rounded-2xl p-5 border border-gray-100">
              <div class="flex justify-between items-center text-sm font-medium">
                <span class="text-gray-500">ราคาต่อคน</span>
                <span class="text-gray-900">฿{{ Number(effectivePrice).toLocaleString() }}</span>
              </div>
              <div class="flex justify-between items-center text-sm font-medium">
                <span class="text-gray-500">จำนวนผู้โดยสาร</span>
                <span class="text-gray-900">{{ passengers.length }} คน</span>
              </div>
              <div class="pt-4 border-t border-dashed border-gray-300 flex justify-between items-end">
                <div>
                  <p class="text-sm font-bold text-gray-500 mb-1">ยอดรวมสุทธิ</p>
                  <p class="text-3xl font-extrabold text-teal-700 font-anuphan tracking-tight">
                    <span class="text-lg text-teal-600 mr-1">฿</span>{{ totalAmount.toLocaleString() }}
                  </p>
                </div>
              </div>
            </div>

            <!-- CTA Button per step -->
            <button v-if="step === (isTrekking ? 1 : 0) && hasSeatMap"
              @click="lockAndNext"
              :disabled="!seatsStore.hasSelectedSeats || lockingSeats"
              class="w-full bg-teal-600 text-white py-4 rounded-2xl font-bold text-base hover:bg-teal-700 active:scale-95 transition-all duration-300 shadow-lg shadow-teal-600/20 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-teal-600 flex items-center justify-center gap-2">
              <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">lock</span>
              <span>{{ lockingSeats ? 'กำลังล็อคที่นั่ง...' : 'ยืนยันการเลือกที่นั่ง' }}</span>
            </button>

            <button v-else-if="(step === (isTrekking ? 1 : 0) && !hasSeatMap) || step === (isTrekking ? 2 : 1)"
              @click="goToSummary"
              :disabled="!isPassengerValid"
              class="w-full bg-teal-600 text-white py-4 rounded-2xl font-bold text-base hover:bg-teal-700 active:scale-95 transition-all duration-300 shadow-lg shadow-teal-600/20 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-teal-600 flex items-center justify-center gap-2">
              <span>ดูสรุปการจอง</span>
              <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">arrow_forward</span>
            </button>

            <button v-else-if="step === (isTrekking ? 3 : 2)"
              @click="createBooking"
              :disabled="bookingLoading"
              class="w-full bg-teal-600 text-white py-4 rounded-2xl font-bold text-base hover:bg-teal-700 active:scale-95 transition-all duration-300 shadow-lg shadow-teal-600/20 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-teal-600 flex items-center justify-center gap-2">
              <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">payment</span>
              <span>{{ bookingLoading ? 'กำลังสร้างการจอง...' : 'ยืนยันและชำระเงิน' }}</span>
            </button>

            <p class="text-center text-xs text-gray-500 font-medium mt-4 flex items-center justify-center gap-1.5 bg-gray-50 py-2 rounded-xl border border-gray-100">
              <span class="material-symbols-rounded text-[14px]">info</span>
              สามารถยกเลิกได้ก่อนการเดินทางภายใน 60 วัน
            </p>
          </div>

          <!-- Vehicle Info Card -->
          <div v-if="schedule.vehicle" class="bg-white rounded-3xl overflow-hidden shadow-sm border border-gray-100">

            <!-- Image Carousel -->
            <div v-if="schedule.vehicle.images && schedule.vehicle.images.length" class="relative group">
              <div class="overflow-hidden" style="aspect-ratio:16/9;">
                <img
                  :src="schedule.vehicle.images[vehicleImageIndex]"
                  :alt="schedule.vehicle.name"
                  class="w-full h-full object-cover transition-opacity duration-300"
                />
              </div>

              <!-- Prev / Next arrows -->
              <button v-if="schedule.vehicle.images.length > 1"
                @click="vehicleImageIndex = (vehicleImageIndex - 1 + schedule.vehicle.images.length) % schedule.vehicle.images.length"
                class="absolute left-3 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/30 hover:bg-black/50 text-white backdrop-blur-sm flex items-center justify-center transition-all opacity-0 group-hover:opacity-100 -translate-x-2 group-hover:translate-x-0">
                <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">chevron_left</span>
              </button>
              <button v-if="schedule.vehicle.images.length > 1"
                @click="vehicleImageIndex = (vehicleImageIndex + 1) % schedule.vehicle.images.length"
                class="absolute right-3 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/30 hover:bg-black/50 text-white backdrop-blur-sm flex items-center justify-center transition-all opacity-0 group-hover:opacity-100 translate-x-2 group-hover:translate-x-0">
                <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">chevron_right</span>
              </button>

              <!-- Dot indicators -->
              <div v-if="schedule.vehicle.images.length > 1" class="absolute bottom-3 left-1/2 -translate-x-1/2 flex gap-1.5 bg-black/20 backdrop-blur-sm px-2 py-1.5 rounded-full">
                <button
                  v-for="(_, i) in schedule.vehicle.images" :key="i"
                  @click="vehicleImageIndex = i"
                  class="w-1.5 h-1.5 rounded-full transition-all"
                  :class="vehicleImageIndex === i ? 'bg-white scale-125 w-3' : 'bg-white/50 hover:bg-white/80'">
                </button>
              </div>

              <!-- Image counter -->
              <div class="absolute top-3 right-3 px-2.5 py-1 rounded-lg bg-black/40 backdrop-blur-sm text-white text-xs font-bold shadow-sm">
                {{ vehicleImageIndex + 1 }}/{{ schedule.vehicle.images.length }}
              </div>
            </div>

            <!-- Info row -->
            <div class="p-5 flex items-center gap-4">
              <div class="w-12 h-12 rounded-2xl bg-gray-100 flex items-center justify-center border border-gray-200 shadow-sm shrink-0">
                <span class="material-symbols-rounded text-teal-600 text-[24px]" style="font-variation-settings:'FILL' 1,'wght' 400">directions_bus</span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="font-bold text-gray-900 text-base truncate mb-1">{{ schedule.vehicle.name }}</p>
                <div class="flex flex-wrap gap-2 text-xs font-medium text-gray-500">
                  <span class="bg-gray-100 px-2 py-0.5 rounded-md">{{ schedule.vehicle.capacity }} ที่นั่ง</span>
                  <span v-if="schedule.vehicle.color" class="bg-gray-100 px-2 py-0.5 rounded-md">{{ schedule.vehicle.color }}</span>
                  <span v-if="schedule.vehicle.license_plate" class="bg-gray-100 px-2 py-0.5 rounded-md border border-gray-200 text-gray-700">{{ schedule.vehicle.license_plate }}</span>
                </div>
                <p v-if="schedule.vehicle.driver_name" class="text-xs text-gray-500 mt-2 flex items-center gap-1.5 font-medium">
                  <span class="material-symbols-rounded text-[14px]">person</span>
                  คนขับ: <span class="text-gray-900">{{ schedule.vehicle.driver_name }}</span>
                </p>
              </div>
            </div>

          </div>

        </aside>
      </div>
    </div>

    <!-- Not found -->
    <div v-else class="flex flex-col items-center justify-center min-h-[50vh] text-gray-500">
      <span class="material-symbols-rounded text-[80px] mb-4 text-gray-300" style="font-variation-settings:'FILL' 0,'wght' 300">sentiment_dissatisfied</span>
      <p class="text-lg font-bold text-gray-700 mb-1">ไม่พบข้อมูลรอบเดินทาง</p>
      <p class="text-sm">อาจถูกยกเลิก หรือ ไม่มีในระบบ</p>
      <router-link to="/trips" class="mt-6 bg-white border-2 border-gray-200 text-gray-700 px-6 py-3 rounded-2xl font-bold text-sm hover:bg-gray-50 transition-colors shadow-sm">
        กลับไปหน้ากิจกรรม
      </router-link>
    </div>
    
    <!-- Insurance Detail Modal -->
    <Teleport to="body">
      <div v-if="showInsuranceModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm transition-opacity" @click="showInsuranceModal = false"></div>
        <div class="bg-white rounded-[32px] w-full max-w-lg relative z-10 shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-200">
          <div class="bg-teal-600 p-8 text-white relative">
            <div class="absolute top-0 right-0 p-6">
              <button @click="showInsuranceModal = false" class="w-10 h-10 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center transition-colors">
                <span class="material-symbols-rounded">close</span>
              </button>
            </div>
            <div class="w-16 h-16 rounded-2xl bg-white/20 flex items-center justify-center mb-4">
              <span class="material-symbols-rounded text-4xl" style="font-variation-settings:'FILL' 1">security</span>
            </div>
            <h3 class="text-2xl font-bold">รายละเอียดความคุ้มครองประกันภัย</h3>
            <p class="text-white/80 text-sm mt-1">เพื่อความปลอดภัยและความอุ่นใจในการเดินทางไปกับเรา</p>
          </div>
          <div class="p-8 space-y-6">
            <div class="flex gap-4">
              <div class="w-10 h-10 rounded-xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100">
                <span class="material-symbols-rounded text-teal-600">health_and_safety</span>
              </div>
              <div class="flex-1">
                <h4 class="font-bold text-gray-900 text-sm mb-1">การเสียชีวิตและทุพพลภาพ</h4>
                <p class="text-sm text-gray-600 leading-relaxed">การเสียชีวิต การสูญเสียอวัยวะ สายตาหรือทุพพลภาพถาวรสิ้นเชิง เนื่องจากอุบัติเหตุ (อ.บ.1) <span class="font-bold text-teal-700">500,000 บาท</span> (รวมการถูกฆาตกรรมหรือทำร้ายร่างกาย)</p>
              </div>
            </div>
            <div class="flex gap-4">
              <div class="w-10 h-10 rounded-xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100">
                <span class="material-symbols-rounded text-teal-600">medical_services</span>
              </div>
              <div class="flex-1">
                <h4 class="font-bold text-gray-900 text-sm mb-1">ค่ารักษาพยาบาล</h4>
                <p class="text-sm text-gray-600 leading-relaxed">การรักษาพยาบาลการบาดเจ็บจากอุบัติเหตุ <span class="font-bold text-teal-700">100,000 บาท</span> (ต่ออุบัติเหตุแต่ละครั้ง)</p>
              </div>
            </div>
            <div class="flex gap-4">
              <div class="w-10 h-10 rounded-xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100">
                <span class="material-symbols-rounded text-teal-600">emergency_home</span>
              </div>
              <div class="flex-1">
                <h4 class="font-bold text-gray-900 text-sm mb-1">การเคลื่อนย้ายฉุกเฉิน</h4>
                <p class="text-sm text-gray-600 leading-relaxed">การเคลื่อนย้ายเพื่อรักษาพยาบาลฉุกเฉินและการเคลื่อนย้ายข้ามจังหวัด <span class="font-bold text-teal-700">50,000 บาท</span></p>
              </div>
            </div>
            
            <button @click="showInsuranceModal = false" class="w-full bg-teal-600 text-white font-bold py-4 rounded-2xl hover:bg-teal-700 active:scale-[0.98] transition-all shadow-lg shadow-teal-600/20 mt-4">
              รับทราบ
            </button>
          </div>
        </div>
      </div>
    </Teleport>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import api from '../lib/axios';
import { useSeatsStore } from '../stores/seats';
import { useBookingStore } from '../stores/booking';
import SeatMap from '../components/SeatMap.vue';
import CountdownTimer from '../components/CountdownTimer.vue';
import { useSwal } from '../lib/swal';
import { useToast } from '../lib/toast';

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const seatsStore = useSeatsStore();
const bookingStore = useBookingStore();
const swal = useSwal();
const toast = useToast();

const schedule = ref(null);
const loading = ref(true);
const step = ref(0);
const lockingSeats = ref(false);
const seatError = ref('');
const bookingLoading = ref(false);
const vehicleImageIndex = ref(0);
const bookingError = ref('');
const passengerCount = ref(1);
const isGroup = ref(false);
const groupName = ref('');
const groupNotes = ref('');
const showInsuranceModal = ref(false);

const hasSeatMap = computed(() => seatsStore.seatMap?.has_seat_map ?? false);
const isDiving = computed(() => ['diving', 'snorkeling'].includes(schedule.value?.trip?.type));
const isTrekking = computed(() => schedule.value?.trip?.type === 'trekking');
const maxPassengers = computed(() => Math.min(schedule.value?.available_seats || 10, 10));

const pickupPoints = computed(() => schedule.value?.pickup_points || []);
const selectedPickup = ref(null);

const steps = computed(() => {
  if (isTrekking.value) {
    if (hasSeatMap.value) return ['เลือกภูมิภาค', 'เลือกที่นั่ง', 'ข้อมูลผู้จอง', 'สรุป'];
    return ['เลือกภูมิภาค', 'ข้อมูลผู้จอง', 'สรุป'];
  }
  if (hasSeatMap.value) return ['เลือกที่นั่ง', 'ข้อมูลผู้จอง', 'สรุป'];
  return ['ข้อมูลผู้จอง', 'สรุป'];
});

const effectivePrice = computed(() => {
  if (selectedPickup.value) return Number(selectedPickup.value.price);
  return Number(schedule.value?.price || 0);
});

const passengers = ref([{ 
  title: '', name: '', nickname: '', id_card: '', phone: '', blood_group: '', allergies: '',
  health_notes: '', emergency_contact: '', emergency_phone: '', 
  dive_cert_level: '', cert_number: '', weight: null 
}]);

watch(passengerCount, (n) => {
  while (passengers.value.length < n) {
    passengers.value.push({ 
      title: '', name: '', nickname: '', id_card: '', phone: '', blood_group: '', allergies: '',
      health_notes: '', emergency_contact: '', emergency_phone: '', 
      dive_cert_level: '', cert_number: '', weight: null 
    });
  }
  if (passengers.value.length > n) passengers.value.length = n;
});

function autoFillFromProfile(index) {
  if (!authStore.user) return;
  const user = authStore.user;
  
  const isFemaleTrip = schedule.value?.trip?.is_women_only;
  const isMaleTitle = user.title === 'นาย';
  
  if (isFemaleTrip && isMaleTitle) {
    swal.warning(
      'ทริปสำหรับผู้หญิงเท่านั้น',
      'ขออภัยครับ ทริปนี้จำกัดเฉพาะผู้หญิงเท่านั้น ระบบจะดึงข้อมูลส่วนอื่นๆ ยกเว้นคำนำหน้าชื่อครับ'
    );
  }

  passengers.value[index] = {
    ...passengers.value[index],
    title: (isFemaleTrip && isMaleTitle) ? '' : (user.title || ''),
    name: user.name || '',
    nickname: user.nickname || '',
    id_card: user.id_card || '',
    phone: user.phone || '',
    blood_group: user.blood_group || '',
    emergency_contact: user.emergency_contact || '',
    emergency_phone: user.emergency_phone || '',
    allergies: user.allergies || '',
    health_notes: user.health_notes || '',
  };
  
  if (!(isFemaleTrip && isMaleTitle)) {
    toast.success('ดึงข้อมูลจากโปรไฟล์สำเร็จ');
  }
}

const isPassengerValid = computed(() => passengers.value.every(p => 
  p.title &&
  p.name?.trim() && 
  p.id_card && 
  p.id_card.length === 13 &&
  (!schedule.value?.trip?.is_women_only || ['นาง', 'นางสาว'].includes(p.title))
));
const totalAmount = computed(() => effectivePrice.value * passengers.value.length);

function formatDate(d) {
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

function selectRegion(pt) {
  selectedPickup.value = pt;
}

function confirmRegion() {
  // Move to seat map step (step 1) or passenger step (step 1) — step 0 is region
  step.value = 1;
}

function skipRegionStep() {
  selectedPickup.value = null;
  step.value = 1;
}

async function lockAndNext() {
  lockingSeats.value = true;
  seatError.value = '';
  try {
    await seatsStore.lockSeats(route.params.scheduleId, seatsStore.selectedSeatIds);
    passengerCount.value = seatsStore.selectedSeats.length;
    step.value = isTrekking.value ? 2 : 1;
    toast.success(`ล็อคที่นั่ง ${seatsStore.selectedSeatIds.join(', ')} สำเร็จ`);
  } catch (e) {
    seatError.value = e?.message || 'ไม่สามารถล็อคที่นั่งได้';
    toast.error(seatError.value);
  } finally {
    lockingSeats.value = false;
  }
}

function goToSummary() {
  if (!isPassengerValid.value) return;
  step.value = isTrekking.value ? 3 : 2;
}

async function createBooking() {
  const { isConfirmed } = await swal.confirm({
    title: 'ยืนยันการจอง?',
    text: `จำนวน ${passengers.value.length} ท่าน · ฿${totalAmount.value.toLocaleString()}`,
    imageUrl: '/images/booking_confirm.png',
    imageWidth: 200,
    imageHeight: 180,
    imageAlt: 'ยืนยันการจอง',
    confirmText: 'ยืนยันและชำระเงิน',
    cancelText: 'ยกเลิก',
  });
  if (!isConfirmed) return;

  bookingLoading.value = true;
  bookingError.value = '';
  try {
    const data = {
      schedule_id: parseInt(route.params.scheduleId),
      pickup_region: selectedPickup.value?.region || null,
      is_group: isGroup.value,
      group_name: isGroup.value ? groupName.value : null,
      group_notes: isGroup.value ? groupNotes.value : null,
      passengers: passengers.value.map(p => ({
        title: p.title || null,
        name: p.name,
        nickname: p.nickname || null,
        id_card: p.id_card || null,
        phone: p.phone || null,
        blood_group: p.blood_group || null,
        allergies: p.allergies || null,
        health_notes: p.health_notes || null,
        emergency_contact: p.emergency_contact || null,
        emergency_phone: p.emergency_phone || null,
        dive_cert_level: p.dive_cert_level || null,
        cert_number: p.cert_number || null,
        weight: p.weight || null,
      })),
    };
    if (hasSeatMap.value) data.seat_ids = seatsStore.selectedSeatIds;

    const res = await bookingStore.createBooking(data);
    toast.success('สร้างการจองสำเร็จ! กำลังไปยังหน้าชำระเงิน...');
    router.push(`/payment/${res.data.booking_ref}`);
  } catch (e) {
    bookingError.value = e?.response?.data?.message || 'ไม่สามารถสร้างการจองได้';
    toast.error(bookingError.value);
  } finally {
    bookingLoading.value = false;
  }
}

onMounted(async () => {
  try {
    const res = await api.get(`/schedules/${route.params.scheduleId}`);
    schedule.value = res.data.data;
    await seatsStore.fetchSeatMap(route.params.scheduleId);
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
  }
});

onBeforeUnmount(() => {
  seatsStore.clearSelection();
});
</script>
