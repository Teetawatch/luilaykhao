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

      <!-- Title Hero -->
      <div class="mb-10 bg-white rounded-3xl shadow-sm border border-gray-100 relative overflow-hidden">
        <!-- Hero Image -->
        <div v-if="schedule.trip?.cover_image" class="relative w-full h-64 md:h-80">
          <img :src="schedule.trip.thumbnail_image || schedule.trip.cover_image" :alt="schedule.trip.title"
            class="w-full h-full object-cover" />
          <div class="absolute inset-0 bg-gradient-to-t from-black/50 via-black/20 to-transparent"></div>
        </div>
        <!-- Content -->
        <div class="p-6 md:p-8 relative" :class="schedule.trip?.cover_image ? '-mt-20 md:-mt-24 relative z-10' : ''">
          <div class="bg-white/95 backdrop-blur-sm rounded-2xl p-6 md:p-8 shadow-lg border border-gray-100/50">
            <h1 class="font-anuphan text-2xl md:text-4xl font-extrabold text-gray-900 mb-4">
              {{ schedule.trip?.title }}
            </h1>
            <div class="flex flex-wrap gap-4 text-sm font-medium">
              <div class="flex items-center gap-2 bg-gray-50 px-4 py-2 rounded-xl text-gray-700 border border-gray-200/60">
                <span class="material-symbols-rounded text-teal-600 text-[20px]" style="font-variation-settings:'FILL' 1,'wght' 400">calendar_month</span>
                <span>
                  {{ formatDate(schedule.departure_date) }}
                  <template v-if="schedule.return_date !== schedule.departure_date"> — {{ formatDate(schedule.return_date) }}</template>
                </span>
              </div>
              <div class="flex items-center gap-2 px-4 py-2 rounded-xl border font-bold" :class="scheduleAvailabilityPillClass">
                <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 1,'wght' 400">event_seat</span>
                <span v-if="isJoinTrip">ว่าง {{ schedule.available_seats }} ที่</span>
                <span v-else>ว่าง {{ schedule.available_seats }}/{{ schedule.total_seats }} ที่นั่ง</span>
              </div>
              <div v-if="isJoinTrip" class="flex items-center gap-2 bg-emerald-50 px-4 py-2 rounded-xl text-emerald-700 border border-emerald-200 font-bold">
                <span class="material-symbols-rounded text-emerald-600 text-[20px]">confirmation_number</span>
                <span>Enjoy Trip (Join Trip)</span>
              </div>
              <div v-if="schedule.trip?.is_women_only" class="flex items-center gap-2 bg-pink-50 px-4 py-2 rounded-xl text-pink-700 border border-pink-200 animate-pulse">
                <span class="material-symbols-rounded text-pink-600 text-[20px]" style="font-variation-settings:'FILL' 1,'wght' 400">female</span>
                <span class="font-bold">ทริปสำหรับผู้หญิงเท่านั้น</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Progress Stepper -->
      <div class="flex justify-center mb-12">
        <div class="flex items-center w-full max-w-3xl relative">
          <template v-for="(s, i) in steps" :key="i">
            <div class="flex flex-col items-center flex-1 relative z-10">
              <!-- Step Circle -->
              <div class="w-10 h-10 md:w-12 md:h-12 rounded-2xl flex items-center justify-center mb-3 transition-all duration-500 shadow-sm"
                :class="step > i
                  ? 'bg-teal-600 text-white shadow-teal-600/20'
                  : step === i
                    ? 'bg-teal-700 text-white ring-4 ring-teal-600/20 scale-110 shadow-teal-700/30'
                    : 'bg-white border-2 border-gray-200 text-gray-400'">
                <span v-if="step > i" class="material-symbols-rounded text-xl md:text-[22px]" style="font-variation-settings:'FILL' 1,'wght' 600">check</span>
                <span v-else class="text-sm md:text-base font-bold">{{ i + 1 }}</span>
              </div>
              <!-- Step Label -->
              <span class="text-xs md:text-sm font-bold text-center transition-colors duration-300 whitespace-nowrap"
                :class="step === i ? 'text-teal-700' : step > i ? 'text-teal-600' : 'text-gray-400'">
                {{ s }}
              </span>
            </div>
            <!-- Progress Line -->
            <div v-if="i < steps.length - 1" class="h-1 flex-1 mx-2 mb-8 transition-all duration-700 rounded-full bg-gray-100 overflow-hidden">
              <div class="h-full bg-teal-600 transition-all duration-700 ease-out" 
                   :style="{ width: step > i ? '100%' : '0%' }"></div>
            </div>
          </template>
        </div>
      </div>

      <!-- Step: Region Picker (Trekking only, before seat map / passenger info) -->
      <div v-if="isTrekking && step === 0" class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        <div class="lg:col-span-7 xl:col-span-8"> 
          <div class="mb-8 bg-white p-6 md:p-8 rounded-3xl shadow-sm border border-gray-100">
            <div class="flex items-center gap-3 mb-2">
              <div class="w-8 h-8 rounded-full bg-teal-100 text-teal-600 flex items-center justify-center">
                <span class="material-symbols-rounded text-lg">location_on</span>
              </div>
              <h2 class="text-2xl font-bold text-gray-900">เลือกจุดขึ้นรถของคุณ</h2>
            </div>
            <p class="text-gray-500">กรุณาเลือกจุดนัดพบที่สะดวกที่สุดสำหรับการเดินทางของคุณ</p>
          </div>

          <div v-if="!pickupPoints.length" class="flex flex-col items-center justify-center py-20 text-gray-400 bg-white rounded-3xl shadow-sm border border-gray-100">
            <div class="w-20 h-20 rounded-full bg-gray-50 flex items-center justify-center mb-4">
              <span class="material-symbols-rounded text-6xl text-gray-300" style="font-variation-settings:'FILL' 0,'wght' 300">map</span>
            </div>
            <p class="text-lg font-bold text-gray-600">ยังไม่ได้กำหนดจุดรับผู้เดินทาง</p>
            <p class="text-sm text-gray-400 mt-1">ปักหมุดจุดที่สะดวก แล้วจองได้ทันที ไม่มีค่าบริการเพิ่ม</p>

            <div v-if="customPickup" class="mt-5 w-full max-w-sm p-4 rounded-2xl border-2 border-amber-300 bg-amber-50/40 text-left">
              <p class="text-[11px] font-bold text-amber-700 uppercase tracking-wider mb-0.5">จุดรับที่ปักหมุดเอง</p>
              <p class="font-bold text-gray-900 leading-tight">{{ customPickup.label }}</p>
              <p v-if="customPickup.note" class="text-sm text-gray-500 mt-0.5">{{ customPickup.note }}</p>
              <div class="flex gap-3 mt-2">
                <button @click="openCustomPickup" class="text-xs font-bold text-teal-600 hover:text-teal-700">แก้ไขจุด</button>
                <button @click="clearCustomPickup" class="text-xs font-bold text-gray-400 hover:text-red-500">เอาออก</button>
              </div>
            </div>

            <div class="mt-6 flex flex-col sm:flex-row gap-3">
              <button @click="openCustomPickup" class="px-6 py-2 rounded-xl bg-amber-50 text-amber-700 font-bold hover:bg-amber-100 transition-all flex items-center gap-2">
                <span class="material-symbols-rounded text-[18px]">add_location_alt</span>
                {{ customPickup ? 'แก้ไขจุดที่ปักหมุด' : 'ปักหมุดจุดรับเอง' }}
              </button>
              <button @click="skipRegionStep" class="px-6 py-2 rounded-xl bg-teal-50 text-teal-700 font-bold hover:bg-teal-100 transition-all">
                ดำเนินการต่อ
              </button>
            </div>
          </div>

          <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div
              v-for="pt in pickupPoints" :key="pt.id"
              @click="selectRegion(pt)"
              class="group cursor-pointer p-6 rounded-[2rem] border-2 transition-all duration-300 relative overflow-hidden flex flex-col justify-between h-full"
              :class="selectedPickup?.id === pt.id
                ? 'border-emerald-500 bg-emerald-50/30 ring-4 ring-emerald-500/10'
                : 'border-gray-100 bg-white hover:border-teal-500/30 hover:shadow-xl hover:shadow-teal-900/5 hover:-translate-y-1'">

              <div v-if="pt.image_url" class="relative z-10 -mx-6 -mt-6 mb-4 h-36 overflow-hidden rounded-t-[2rem]">
                <img :src="pt.image_url" :alt="pt.pickup_location" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105" loading="lazy" />
                <div class="absolute inset-0 bg-gradient-to-t from-black/30 to-transparent"></div>
              </div>

              <div class="relative z-10">
                <div class="flex items-start justify-between mb-4">
                  <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider transition-colors"
                      :class="selectedPickup?.id === pt.id ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-600 group-hover:bg-teal-50 group-hover:text-teal-700'">
                      {{ pt.region_label }}
                    </span>
                    <span v-if="pt.id % 3 === 0" class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-amber-50 text-amber-700 text-[10px] font-bold border border-amber-100">
                      <span class="material-symbols-rounded text-[14px]">stars</span> จุดขึ้นยอดนิยม
                    </span>
                    <span v-if="pt.pickup_location.includes('BTS') || pt.pickup_location.includes('MRT')" class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-blue-50 text-blue-700 text-[10px] font-bold border border-blue-100">
                      <span class="material-symbols-rounded text-[14px]">train</span> ใกล้รถไฟฟ้า
                    </span>
                  </div>
                  
                  <div class="shrink-0">
                    <div v-if="selectedPickup?.id === pt.id" class="w-7 h-7 rounded-full bg-emerald-500 text-white flex items-center justify-center shadow-lg shadow-emerald-500/30 animate-in zoom-in duration-300">
                      <span class="material-symbols-rounded text-lg font-bold">check</span>
                    </div>
                    <div v-else class="w-7 h-7 rounded-full border-2 border-gray-200 group-hover:border-teal-300 transition-colors"></div>
                  </div>
                </div>

                <h3 class="font-bold text-gray-900 text-lg mb-1 group-hover:text-teal-700 transition-colors">{{ pt.pickup_location }}</h3>
                
                <div v-if="pt.notes" class="flex items-center gap-2 text-gray-500 mb-4">
                  <span class="material-symbols-rounded text-[18px] text-teal-600">schedule</span>
                  <span class="text-sm font-medium">{{ pt.notes }}</span>
                </div>
              </div>

              <div class="flex items-center justify-between mt-auto pt-4 border-t border-gray-100/50 relative z-10">
                <a v-if="pt.map_url" :href="pt.map_url" target="_blank"
                  @click.stop
                  class="inline-flex items-center gap-1.5 text-xs font-bold text-gray-400 hover:text-teal-600 transition-colors">
                  <span class="material-symbols-rounded text-[18px]">map</span>
                  ดูแผนที่
                </a>
                <span class="text-xs font-bold text-gray-400 group-hover:text-gray-500 transition-colors">
                  {{ selectedPickup?.id === pt.id ? 'เลือกไว้แล้ว' : 'คลิกเพื่อเลือก' }}
                </span>
              </div>

              <!-- Decorative Background element -->
              <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-teal-500/5 rounded-full blur-2xl transition-all duration-500 group-hover:bg-teal-500/10 group-hover:scale-150"></div>
            </div>
          </div>

          <!-- Custom pickup: ปักหมุดเอง (อยู่ในเส้นทางผ่านที่รับได้) -->
          <div class="mt-6">
            <div v-if="customPickup"
              class="p-5 rounded-[2rem] border-2 border-amber-300 bg-amber-50/40 flex items-start gap-4">
              <div class="w-10 h-10 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center shrink-0">
                <span class="material-symbols-rounded">add_location_alt</span>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-[11px] font-bold text-amber-700 uppercase tracking-wider mb-0.5">จุดรับที่ปักหมุดเอง</p>
                <p class="font-bold text-gray-900 leading-tight">{{ customPickup.label }}</p>
                <p v-if="customPickup.note" class="text-sm text-gray-500 mt-0.5">{{ customPickup.note }}</p>
                <p class="text-xs text-amber-700 mt-2 flex items-center gap-1">
                  <span class="material-symbols-rounded text-[15px]">info</span>
                  จุดรับนี้จะถูกบันทึกในการจอง · ไม่มีค่าบริการเพิ่ม
                </p>
                <div class="flex gap-3 mt-3">
                  <button @click="openCustomPickup" class="text-xs font-bold text-teal-600 hover:text-teal-700">แก้ไขจุด</button>
                  <button @click="clearCustomPickup" class="text-xs font-bold text-gray-400 hover:text-red-500">เอาออก</button>
                </div>
              </div>
            </div>

            <button v-else @click="openCustomPickup"
              class="w-full p-5 rounded-[2rem] border-2 border-dashed border-gray-200 hover:border-teal-400 hover:bg-teal-50/30 transition-all flex items-center justify-center gap-3 text-gray-500 hover:text-teal-700 font-bold">
              <span class="material-symbols-rounded">add_location_alt</span>
              ไม่มีจุดที่สะดวก? ปักหมุดจุดรับเอง
            </button>
          </div>

          <div class="mt-10 flex flex-col sm:flex-row gap-4 justify-between items-center bg-white p-6 rounded-[2rem] shadow-sm border border-gray-100">
            <button @click="$router.push('/trips')"
              class="w-full sm:w-auto flex items-center justify-center gap-2 text-gray-500 hover:text-gray-900 px-6 py-3 rounded-2xl font-bold transition-all">
              <span class="material-symbols-rounded text-[20px]">arrow_back</span>
              ยกเลิก
            </button>
            
            <div class="flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
              <button
                @click="confirmRegion"
                :disabled="!selectedPickup && !customPickup && pickupPoints.length > 0"
                class="w-full sm:w-auto bg-teal-600 text-white px-10 py-4 rounded-2xl font-bold text-lg hover:bg-teal-700 active:scale-95 transition-all duration-300 shadow-xl shadow-teal-600/20 disabled:opacity-50 disabled:grayscale disabled:cursor-not-allowed group flex items-center justify-center gap-3">
                <span>{{ pickupPoints.length ? 'ไปเลือกที่นั่ง' : 'ขั้นตอนถัดไป' }}</span>
                <span class="material-symbols-rounded transition-transform group-hover:translate-x-1">arrow_forward</span>
              </button>
            </div>
          </div>
          <p v-if="!selectedPickup && !customPickup && pickupPoints.length > 0" class="text-center mt-4 text-sm text-red-500 font-bold animate-pulse">
            * กรุณาเลือกจุดขึ้นรถก่อนเดินทางต่อ
          </p>
        </div>

        <!-- Sidebar region summary -->
        <aside class="lg:col-span-5 xl:col-span-4 sticky transition-all duration-300 z-30"
          :class="seatsStore.hasActiveBooking ? 'top-[140px]' : 'top-[100px]'">
          <div class="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100 overflow-hidden relative">
            <h2 class="text-xl font-bold mb-6 flex items-center gap-2 text-gray-900">
              <span class="material-symbols-rounded text-teal-600">receipt_long</span>
              สรุปรายการจอง
            </h2>
            
            <!-- Trip Basic Info -->
            <div class="mb-6 p-4 rounded-3xl bg-gray-50 border border-gray-100">
              <p class="text-[11px] font-bold text-teal-700 uppercase tracking-[0.1em] mb-3">รายละเอียดทริป</p>
              <div class="space-y-3">
                <div class="flex items-start gap-3">
                  <span class="material-symbols-rounded text-teal-600 text-xl">calendar_today</span>
                  <div>
                    <p class="text-xs text-gray-500 font-medium">วันเดินทาง</p>
                    <p class="text-sm font-bold text-gray-900">{{ formatDate(schedule.departure_date) }}</p>
                  </div>
                </div>
                <div v-if="selectedPickup" class="flex items-start gap-3 animate-in fade-in slide-in-from-left-2 duration-300">
                  <span class="material-symbols-rounded text-emerald-600 text-xl">location_on</span>
                  <div>
                    <p class="text-xs text-gray-500 font-medium">จุดขึ้นรถ</p>
                    <p class="text-sm font-bold text-gray-900 leading-tight">{{ selectedPickup.pickup_location }}</p>
                    <p class="text-[11px] text-emerald-700 font-bold mt-1 bg-emerald-100 px-2 py-0.5 rounded-full w-fit">{{ selectedPickup.region_label }}</p>
                    <img v-if="selectedPickup.image_url" :src="selectedPickup.image_url" :alt="selectedPickup.pickup_location" class="mt-2 w-full max-w-[220px] h-28 object-cover rounded-xl border border-gray-100" loading="lazy" />
                  </div>
                </div>
              </div>
            </div>

            <div class="space-y-4 bg-gray-50 rounded-3xl p-5 border border-gray-100">
              <div class="flex justify-between items-center text-sm">
                <span class="text-gray-500 font-medium">ราคาเริ่มต้น</span>
                <span class="text-gray-900 font-bold">฿{{ Number(schedule.price).toLocaleString() }}</span>
              </div>
              <div v-if="selectedPickup && Number(selectedPickup.price) !== Number(schedule.price)" class="flex justify-between items-center text-sm text-emerald-700">
                <span class="font-medium">ส่วนต่างภูมิภาค</span>
                <span class="font-bold">+฿{{ (Number(selectedPickup.price) - Number(schedule.price)).toLocaleString() }}</span>
              </div>
              
              <div class="pt-4 border-t border-dashed border-gray-300 flex flex-col gap-1">
                <div class="flex justify-between items-end">
                  <span class="text-sm font-bold text-gray-500 mb-1">ราคาต่อคน</span>
                  <div class="text-right">
                    <span class="text-3xl font-extrabold text-teal-700 font-anuphan tracking-tight">
                      <span class="text-lg text-teal-600 mr-0.5">฿</span>{{ (selectedPickup ? Number(selectedPickup.price) : Number(schedule.price)).toLocaleString() }}
                    </span>
                  </div>
                </div>
                <p class="text-[10px] text-gray-400 text-right">* ราคานี้รวมค่าหัวและประกันภัยแล้ว</p>
              </div>
            </div>

            <p class="text-center text-[11px] text-gray-400 font-medium mt-6">
              เลือกภูมิภาคที่ต้องการเพื่อดำเนินการในขั้นตอนถัดไป
            </p>
          </div>
        </aside>
      </div>

      <!-- Main Content Grid -->
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">

        <!-- Left: Main Steps -->
        <div class="lg:col-span-7 xl:col-span-8" v-if="!isTrekking || step > 0">

          <!-- Step 0: Seat Map -->
          <div v-if="step === (isTrekking ? 1 : 0) && hasSeatMap" class="space-y-6">

            <!-- Header card -->
            <div class="bg-white rounded-3xl border border-gray-100 shadow-sm overflow-hidden">
              <div class="px-6 py-5 flex items-center justify-between gap-4">
                <div>
                  <h2 class="text-xl font-black text-gray-900 mb-1 flex items-center gap-2">
                    <span class="material-symbols-rounded text-teal-600 text-[22px]" style="font-variation-settings:'FILL' 1">airline_seat_recline_extra</span>
                    เลือกที่นั่งของคุณ
                  </h2>
                  <p class="text-sm text-gray-400 font-medium">แตะที่นั่งสีขาวเพื่อเลือก · แตะซ้ำเพื่อยกเลิก</p>
                </div>
                <!-- Live seat counter -->
                <div v-if="seatsStore.seatMap" class="shrink-0 text-right">
                  <p class="text-2xl font-black leading-none"
                    :class="seatsStore.seatMap.available_seats === 0 ? 'text-red-500' : seatsStore.seatMap.available_seats <= 3 ? 'text-amber-500' : 'text-teal-600'">
                    {{ seatsStore.seatMap.available_seats }}
                  </p>
                  <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mt-0.5">ที่ว่าง</p>
                </div>
              </div>
              <!-- Seat progress bar -->
              <div v-if="seatsStore.seatMap" class="px-6 pb-5">
                <div class="flex items-center gap-3">
                  <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div
                      class="h-full rounded-full transition-all duration-700"
                      :class="seatsStore.seatMap.available_seats === 0 ? 'bg-red-400' : seatsStore.seatMap.available_seats <= 3 ? 'bg-amber-400' : 'bg-teal-500'"
                      :style="{ width: `${Math.round((seatsStore.seatMap.booked_seats / seatsStore.seatMap.total_seats) * 100)}%` }"
                    ></div>
                  </div>
                  <span class="text-[11px] font-bold text-gray-400 shrink-0">จองแล้ว {{ seatsStore.seatMap.booked_seats }}/{{ seatsStore.seatMap.total_seats }}</span>
                </div>
                <p v-if="seatsStore.seatMap.available_seats > 0 && seatsStore.seatMap.available_seats <= 3"
                  class="mt-2 text-[11px] font-black text-amber-600 flex items-center gap-1">
                  <span class="material-symbols-rounded text-[14px]" style="font-variation-settings:'FILL' 1">warning</span>
                  เหลือเพียง {{ seatsStore.seatMap.available_seats }} ที่นั่ง — จองด่วน!
                </p>
              </div>
            </div>

            <!-- Seat Map -->
            <SeatMap :seat-map="seatsStore.seatMap" :is-women-only="schedule.trip?.is_women_only" />

            <!-- Error -->
            <p v-if="seatError" class="flex items-center gap-2 text-red-600 text-sm p-4 bg-red-50 border border-red-100 rounded-2xl">
              <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 1,'wght' 400">error</span>
              {{ seatError }}
            </p>

            <!-- Vehicle Info -->
            <div v-if="schedule.vehicle" class="bg-white rounded-3xl overflow-hidden border border-gray-100 shadow-sm">

              <!-- Section label -->
              <div class="px-5 pt-5 pb-3 flex items-center gap-2 border-b border-gray-50">
                <span class="material-symbols-rounded text-teal-600 text-[18px]" style="font-variation-settings:'FILL' 1">airport_shuttle</span>
                <span class="text-xs font-black text-gray-500 uppercase tracking-wider">รายละเอียดรถ</span>
              </div>

              <!-- Image Carousel -->
              <div v-if="schedule.vehicle.images && schedule.vehicle.images.length"
                class="relative group touch-pan-y overflow-hidden"
                @touchstart="vehicleTouchStart"
                @touchend="vehicleTouchEnd">
                <div class="overflow-hidden relative bg-gray-100 z-0" style="aspect-ratio:16/9; touch-action: pan-y;">
                  <img
                    v-for="(img, i) in schedule.vehicle.images"
                    :key="i"
                    :src="img"
                    :alt="schedule.vehicle.name"
                    class="absolute inset-0 w-full h-full object-cover transition-all duration-500 transform-gpu"
                    :class="vehicleImageIndex === i ? 'opacity-100 scale-100 z-10' : 'opacity-0 scale-110 z-0 pointer-events-none'"
                    :loading="i === 0 ? 'eager' : 'lazy'"
                    style="will-change: opacity, transform;"
                  />
                  <!-- Gradient overlay -->
                  <div class="absolute inset-0 bg-gradient-to-t from-black/30 to-transparent z-10 pointer-events-none"></div>
                </div>

                <!-- Nav buttons -->
                <button v-if="schedule.vehicle.images.length > 1"
                  @click.stop="vehicleImageIndex = (vehicleImageIndex - 1 + schedule.vehicle.images.length) % schedule.vehicle.images.length"
                  class="absolute left-3 top-1/2 -translate-y-1/2 w-9 h-9 rounded-full bg-black/40 hover:bg-black/60 text-white backdrop-blur-md flex items-center justify-center transition-all z-20 opacity-100 md:opacity-0 md:group-hover:opacity-100 active:scale-90">
                  <span class="material-symbols-rounded text-[22px]">chevron_left</span>
                </button>
                <button v-if="schedule.vehicle.images.length > 1"
                  @click.stop="vehicleImageIndex = (vehicleImageIndex + 1) % schedule.vehicle.images.length"
                  class="absolute right-3 top-1/2 -translate-y-1/2 w-9 h-9 rounded-full bg-black/40 hover:bg-black/60 text-white backdrop-blur-md flex items-center justify-center transition-all z-20 opacity-100 md:opacity-0 md:group-hover:opacity-100 active:scale-90">
                  <span class="material-symbols-rounded text-[22px]">chevron_right</span>
                </button>

                <!-- Dot indicators -->
                <div v-if="schedule.vehicle.images.length > 1"
                  class="absolute bottom-3 left-1/2 -translate-x-1/2 flex gap-1.5 z-20">
                  <button v-for="(_, i) in schedule.vehicle.images" :key="i"
                    @click.stop="vehicleImageIndex = i"
                    class="rounded-full transition-all touch-manipulation"
                    :class="vehicleImageIndex === i ? 'bg-white w-4 h-1.5' : 'bg-white/50 hover:bg-white/80 w-1.5 h-1.5'">
                  </button>
                </div>

                <div class="absolute top-3 right-3 px-2.5 py-1 rounded-lg bg-black/40 backdrop-blur-md text-white text-[10px] font-black z-20">
                  {{ vehicleImageIndex + 1 }}/{{ schedule.vehicle.images.length }}
                </div>
              </div>

              <!-- Interior video -->
              <div v-if="schedule.vehicle.interior_video" class="px-5 pt-4">
                <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-[15px]">videocam</span>วิดีโอภายในรถ
                </p>
                <video :src="schedule.vehicle.interior_video" controls class="w-full rounded-2xl border border-gray-100"></video>
              </div>

              <!-- Vehicle info -->
              <div class="p-5">
                <div class="flex items-center gap-3">
                  <div class="w-11 h-11 rounded-2xl bg-teal-50 border border-teal-100 flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-teal-600 text-[22px]" style="font-variation-settings:'FILL' 1,'wght' 400">airport_shuttle</span>
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="font-black text-gray-900 text-sm truncate">{{ schedule.vehicle.name }}</p>
                    <div class="flex flex-wrap gap-1.5 mt-1.5">
                      <span class="text-[10px] font-bold bg-teal-50 text-teal-700 border border-teal-100 px-2 py-0.5 rounded-full">
                        {{ schedule.vehicle.capacity }} ที่นั่ง
                      </span>
                      <span v-if="schedule.vehicle.color" class="text-[10px] font-bold bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">
                        {{ schedule.vehicle.color }}
                      </span>
                      <span v-if="schedule.vehicle.license_plate" class="text-[10px] font-black bg-gray-900 text-white px-2.5 py-0.5 rounded-full tracking-wide">
                        {{ schedule.vehicle.license_plate }}
                      </span>
                    </div>
                  </div>
                </div>

                <!-- Driver info -->
                <div v-if="schedule.vehicle.driver_name" class="mt-4 pt-4 border-t border-gray-50 flex items-center gap-3">
                  <div class="shrink-0">
                    <img v-if="schedule.vehicle.driver_photo" :src="schedule.vehicle.driver_photo"
                      class="w-10 h-10 rounded-2xl object-cover border-2 border-gray-100" />
                    <div v-else class="w-10 h-10 rounded-2xl bg-gray-100 border border-gray-200 flex items-center justify-center">
                      <span class="material-symbols-rounded text-[18px] text-gray-400">person</span>
                    </div>
                  </div>
                  <div class="min-w-0">
                    <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">พนักงานขับรถ</p>
                    <p class="text-sm font-black text-gray-900 truncate mt-0.5">{{ schedule.vehicle.driver_name }}</p>
                    <p v-if="schedule.vehicle.driver_phone" class="text-xs font-bold text-teal-600 mt-0.5 flex items-center gap-1">
                      <span class="material-symbols-rounded text-[13px]">call</span>
                      {{ schedule.vehicle.driver_phone }}
                    </p>
                  </div>
                </div>
              </div>

            </div>
          </div>

          <!-- Passenger Info step -->
          <div v-if="(step === (isTrekking ? 1 : 0) && !hasSeatMap) || step === (isTrekking ? 2 : 1)">
            <div class="mb-8 bg-white p-6 rounded-3xl shadow-sm border border-gray-100">
              <h2 class="text-2xl font-bold text-gray-900 mb-2">ข้อมูลผู้เดินทาง</h2>
              <p class="text-gray-500">กรุณากรอกข้อมูลให้ครบถ้วนเพื่อความปลอดภัยในการเดินทาง</p>
            </div>

            <!-- Countdown -->
            <CountdownTimer v-if="seatsStore.countdownSeconds > 0"
              :seconds="seatsStore.countdownSeconds" class="mb-6" />

            <!-- Booking owner mode -->
            <div class="mb-6 p-6 bg-white rounded-3xl border border-gray-100 shadow-sm">
              <div class="flex flex-col md:flex-row md:items-center justify-between gap-5">
                <div>
                  <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                    <span class="material-symbols-rounded text-teal-600">assignment_ind</span>
                    ผู้เดินทางหลัก
                  </h3>
                  <p class="text-sm text-gray-500 mt-1">เลือกว่ากำลังกรอกข้อมูลของตนเอง หรือจองแทนเพื่อนที่ฝากจอง</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 w-full md:w-auto">
                  <button type="button" @click="bookingFor = 'self'"
                    class="px-5 py-3 rounded-2xl border-2 text-sm font-bold transition-all flex items-center justify-center gap-2"
                    :class="bookingFor === 'self' ? 'border-teal-600 bg-teal-50 text-teal-700' : 'border-gray-200 bg-gray-50 text-gray-600 hover:bg-gray-100'">
                    <span class="material-symbols-rounded text-[18px]">person</span>
                    กรอกข้อมูลตนเอง
                  </button>
                  <button type="button" @click="bookingFor = 'friend'"
                    class="px-5 py-3 rounded-2xl border-2 text-sm font-bold transition-all flex items-center justify-center gap-2"
                    :class="bookingFor === 'friend' ? 'border-teal-600 bg-teal-50 text-teal-700' : 'border-gray-200 bg-gray-50 text-gray-600 hover:bg-gray-100'">
                    <span class="material-symbols-rounded text-[18px]">group_add</span>
                    กรอกข้อมูลให้เพื่อน
                  </button>
                </div>
              </div>
              <div v-if="bookingFor === 'friend'" class="mt-5 p-4 rounded-2xl bg-amber-50 border border-amber-100 text-sm text-amber-800 font-medium flex gap-3">
                <span class="material-symbols-rounded text-[20px] shrink-0">mail</span>
                <span>กรุณาระบุอีเมลของเพื่อนในผู้เดินทางคนแรก เพื่อส่งสถานะการจองและการชำระเงินให้เพื่อนโดยตรง</span>
              </div>
            </div>

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
                  <span class="text-sm text-gray-500">จองสำหรับหลายผู้เดินทางในการจองเดียว</span>
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
                จำนวนผู้เดินทาง
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

            <div v-if="optionalAddons.length" class="mb-8 bg-white rounded-3xl border border-gray-100 shadow-sm p-6">
              <div class="flex items-center justify-between gap-4 mb-5">
                <label class="flex items-center gap-2 text-base font-bold text-gray-900">
                  <span class="material-symbols-rounded text-amber-500">add_task</span>
                  ตัวเลือกเสริม
                </label>
                <span v-if="addonsTotal > 0" class="text-sm font-black text-amber-700 bg-amber-50 border border-amber-100 px-3 py-1 rounded-full">
                  +฿{{ addonsTotal.toLocaleString() }}
                </span>
              </div>

              <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <div
                  v-for="addon in optionalAddons"
                  :key="addon.index"
                  @click="toggleAddon(addon.index)"
                  class="relative rounded-2xl border-2 cursor-pointer transition-all overflow-hidden"
                  :class="selectedAddons.includes(addon.index) ? 'border-amber-500 ring-2 ring-amber-200' : 'border-gray-100 hover:border-amber-200'"
                >
                  <!-- image / placeholder -->
                  <div class="relative h-28" :class="addon.image_url ? 'bg-gray-100' : 'bg-amber-50 flex items-center justify-center'">
                    <img v-if="addon.image_url" :src="addon.image_url" :alt="addon.name" class="w-full h-full object-cover" loading="lazy" />
                    <span v-else class="material-symbols-rounded text-amber-300" style="font-size:34px;">add_task</span>
                    <button
                      v-if="addon.image_url"
                      type="button"
                      @click.stop="addonZoomImage = addon.image_url"
                      class="absolute top-1.5 right-1.5 w-7 h-7 rounded-full bg-black/45 text-white flex items-center justify-center hover:bg-black/65"
                      title="ดูรูปใหญ่"
                    >
                      <span class="material-symbols-rounded" style="font-size:16px;">zoom_in</span>
                    </button>
                    <div
                      v-if="selectedAddons.includes(addon.index)"
                      class="absolute top-1.5 left-1.5 w-6 h-6 rounded-full bg-amber-500 text-white flex items-center justify-center shadow"
                    >
                      <span class="material-symbols-rounded" style="font-size:16px;">check</span>
                    </div>
                  </div>
                  <!-- info -->
                  <div class="p-3" :class="selectedAddons.includes(addon.index) ? 'bg-amber-50/60' : 'bg-white'">
                    <p class="font-black text-gray-900 text-sm leading-tight line-clamp-2">{{ addon.name }}</p>
                    <p class="text-[11px] font-bold text-amber-600 mt-1">
                      ฿{{ addon.price.toLocaleString() }} {{ addonPriceTypeLabel(addon) }}
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Add-on image lightbox -->
            <div
              v-if="addonZoomImage"
              class="fixed inset-0 z-[60] bg-black/80 flex items-center justify-center p-4"
              @click="addonZoomImage = null"
            >
              <img :src="addonZoomImage" alt="" class="max-w-full max-h-full object-contain rounded-xl" />
              <button
                type="button"
                class="absolute top-4 right-4 w-11 h-11 rounded-full bg-black/50 text-white flex items-center justify-center"
                @click="addonZoomImage = null"
              >
                <span class="material-symbols-rounded">close</span>
              </button>
            </div>

            <!-- Passenger forms -->
            <div v-for="(p, i) in passengers" :key="i"
              :id="`passenger-card-${i}`"
              class="bg-white border rounded-3xl p-6 md:p-8 mb-6 shadow-sm relative overflow-hidden transition-all hover:shadow-md"
              :class="attempted && Object.keys(passengerErrors[i] || {}).length ? 'border-red-200 ring-1 ring-red-100' : 'border-gray-100 hover:border-gray-200'">
              
              <div class="absolute top-0 left-0 w-2 h-full bg-teal-600 rounded-l-3xl"></div>
              
              <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
                <h3 class="flex items-center gap-3 font-bold text-gray-900 text-lg">
                  <span class="w-10 h-10 rounded-2xl bg-gray-100 text-gray-700 flex items-center justify-center text-base font-bold shadow-sm border border-gray-200">{{ i + 1 }}</span>
                  ผู้เดินทางคนที่ {{ i + 1 }}
                </h3>
                <div class="flex flex-wrap items-center gap-3">
                  <button v-if="i === 0 && authStore.isLoggedIn && bookingFor === 'self'" type="button" @click="autoFillFromProfile(i)"
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

              <!-- Custom pinned pickup (chosen instead of a preset point) -->
              <div v-if="customPickup" class="mb-6 p-4 rounded-2xl bg-amber-50/50 border border-amber-200 flex items-start gap-3">
                <span class="material-symbols-rounded text-amber-600 text-[20px]">add_location_alt</span>
                <div class="min-w-0">
                  <p class="text-[11px] font-bold text-amber-700 uppercase tracking-wider mb-0.5">จุดรับที่ปักหมุดเอง</p>
                  <p class="font-bold text-gray-900 leading-tight">{{ customPickup.label }}</p>
                  <p class="text-xs text-amber-700 mt-1 flex items-center gap-1">
                    <span class="material-symbols-rounded text-[14px]">info</span>
                    จุดรับนี้จะถูกบันทึกในการจอง · ไม่มีค่าบริการเพิ่ม
                  </p>
                </div>
              </div>

              <!-- Per-passenger pickup point selector -->
              <div v-else-if="pickupPoints.length" class="mb-6 p-4 rounded-2xl bg-gray-50 border border-gray-100">
                <label class="block text-sm font-bold text-gray-700 mb-3 flex items-center gap-2">
                  <span class="material-symbols-rounded text-teal-600 text-[18px]">location_on</span>
                  จุดขึ้นรถ <span class="text-red-500">*</span>
                </label>
                <div class="flex flex-wrap gap-2">
                  <button v-for="pt in pickupPoints" :key="pt.id" type="button"
                    @click="p.pickup_point_id = pt.id"
                    class="flex items-center gap-2 px-4 py-2.5 rounded-xl border-2 text-sm font-bold transition-all"
                    :class="p.pickup_point_id === pt.id
                      ? 'border-emerald-500 bg-emerald-50 text-emerald-700'
                      : 'border-gray-200 bg-white text-gray-600 hover:border-teal-400 hover:text-teal-700'">
                    <span class="material-symbols-rounded text-[16px]" v-if="p.pickup_point_id === pt.id">check_circle</span>
                    <span class="material-symbols-rounded text-[16px]" v-else>radio_button_unchecked</span>
                    <span>{{ pt.pickup_location }}</span>
                    <span class="text-[11px] font-normal opacity-70">฿{{ Number(pt.price).toLocaleString() }}</span>
                  </button>
                </div>
                <p v-if="showErr(i, 'pickup_point_id')" class="field-error text-xs text-red-500 font-bold mt-2 flex items-center gap-1">
                  <span class="material-symbols-rounded text-[14px]">error</span>{{ showErr(i, 'pickup_point_id') }}
                </p>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="md:col-span-2 grid grid-cols-12 gap-5">
                  <div class="col-span-12 md:col-span-3">
                    <label class="block text-sm font-bold text-gray-700 mb-2">คำนำหน้า <span class="text-red-500">*</span></label>
                    <select v-model="p.title" required
                      class="w-full border-2 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                      :class="showErr(i, 'title') ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'">
                      <option value="" disabled>เลือก...</option>
                      <option v-if="!schedule.trip?.is_women_only" value="นาย">นาย</option>
                      <option value="นาง">นาง</option>
                      <option value="นางสาว">นางสาว</option>
                    </select>
                    <p v-if="showErr(i, 'title')" class="field-error text-xs text-red-500 font-bold mt-2 flex items-center gap-1">
                      <span class="material-symbols-rounded text-[14px]">error</span>{{ showErr(i, 'title') }}
                    </p>
                  </div>
                  <div class="col-span-12 md:col-span-9">
                    <label class="block text-sm font-bold text-gray-700 mb-2">ชื่อ-นามสกุล <span class="text-red-500">*</span></label>
                    <input v-model="p.name" type="text" required placeholder="กรอกชื่อ-นามสกุล"
                      class="w-full border-2 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                      :class="showErr(i, 'name') ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'" />
                    <p v-if="showErr(i, 'name')" class="field-error text-xs text-red-500 font-bold mt-2 flex items-center gap-1">
                      <span class="material-symbols-rounded text-[14px]">error</span>{{ showErr(i, 'name') }}
                    </p>
                  </div>
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">ชื่อเล่น <span class="text-red-500">*</span></label>
                  <input v-model="p.nickname" type="text" required placeholder="กรอกชื่อเล่น"
                    class="w-full border-2 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                    :class="showErr(i, 'nickname') ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'" />
                  <p v-if="showErr(i, 'nickname')" class="field-error text-xs text-red-500 font-bold mt-2 flex items-center gap-1">
                    <span class="material-symbols-rounded text-[14px]">error</span>{{ showErr(i, 'nickname') }}
                  </p>
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
                    inputmode="numeric" pattern="[0-9]{13}"
                    maxlength="13"
                    @input="limitDigits(p, 'id_card', 13)"
                    class="w-full border-2 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                    :class="showErr(i, 'id_card') ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'" />
                  <p v-if="showErr(i, 'id_card')" class="field-error text-xs text-red-500 font-bold mt-2 flex items-center gap-1">
                    <span class="material-symbols-rounded text-[14px]">error</span>{{ showErr(i, 'id_card') }}
                  </p>
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">วัน/เดือน/ปีเกิด (พ.ศ.) <span class="text-red-500">*</span></label>
                  <div class="grid grid-cols-3 gap-2">
                    <select :value="p.birth_day" @change="updateBirth(p, 'birth_day', $event.target.value)" required
                      class="w-full border-2 rounded-2xl px-3 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                      :class="showErr(i, 'birth_date') ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'">
                      <option value="" disabled>วัน</option>
                      <option v-for="d in daysFor(p)" :key="d" :value="String(d)">{{ d }}</option>
                    </select>
                    <select :value="p.birth_month" @change="updateBirth(p, 'birth_month', $event.target.value)" required
                      class="w-full border-2 rounded-2xl px-3 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                      :class="showErr(i, 'birth_date') ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'">
                      <option value="" disabled>เดือน</option>
                      <option v-for="(m, mi) in thaiMonths" :key="mi" :value="String(mi + 1)">{{ m }}</option>
                    </select>
                    <select :value="p.birth_year" @change="updateBirth(p, 'birth_year', $event.target.value)" required
                      class="w-full border-2 rounded-2xl px-3 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                      :class="showErr(i, 'birth_date') ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'">
                      <option value="" disabled>พ.ศ.</option>
                      <option v-for="y in birthYears" :key="y" :value="String(y)">{{ y + 543 }}</option>
                    </select>
                  </div>
                  <p v-if="showErr(i, 'birth_date')" class="field-error text-xs text-red-500 font-bold mt-2 flex items-center gap-1">
                    <span class="material-symbols-rounded text-[14px]">error</span>{{ showErr(i, 'birth_date') }}
                  </p>
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">เบอร์โทรศัพท์ <span class="text-red-500">*</span></label>
                  <input v-model="p.phone" type="tel" required placeholder="0XXXXXXXXX"
                    inputmode="numeric" pattern="[0-9]{10}" maxlength="10"
                    @input="limitDigits(p, 'phone', 10)"
                    class="w-full border-2 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                    :class="showErr(i, 'phone') ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'" />
                  <p v-if="showErr(i, 'phone')" class="field-error text-xs text-red-500 font-bold mt-2 flex items-center gap-1">
                    <span class="material-symbols-rounded text-[14px]">error</span>{{ showErr(i, 'phone') }}
                  </p>
                </div>
                <div v-if="bookingFor === 'friend' && i === 0">
                  <label class="block text-sm font-bold text-gray-700 mb-2">อีเมลสำหรับแจ้งสถานะการจอง <span class="text-red-500">*</span></label>
                  <input v-model.trim="p.email" type="email" required placeholder="friend@example.com"
                    class="w-full border-2 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                    :class="showErr(i, 'email') ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'" />
                  <p v-if="showErr(i, 'email')" class="field-error text-xs text-red-500 font-bold mt-2 flex items-center gap-1">
                    <span class="material-symbols-rounded text-[14px]">error</span>{{ showErr(i, 'email') }}
                  </p>
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">กรุ๊ปเลือด <span class="text-red-500">*</span></label>
                  <select v-model="p.blood_group" required
                    class="w-full border-2 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                    :class="showErr(i, 'blood_group') ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'">
                    <option value="" disabled>เลือกกรุ๊ปเลือด</option>
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="O">O</option>
                    <option value="AB">AB</option>
                  </select>
                  <p v-if="showErr(i, 'blood_group')" class="field-error text-xs text-red-500 font-bold mt-2 flex items-center gap-1">
                    <span class="material-symbols-rounded text-[14px]">error</span>{{ showErr(i, 'blood_group') }}
                  </p>
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">ผู้ติดต่อฉุกเฉิน <span class="text-red-500">*</span></label>
                  <input v-model="p.emergency_contact" type="text" required placeholder="ชื่อผู้ติดต่อ"
                    class="w-full border-2 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                    :class="showErr(i, 'emergency_contact') ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'" />
                  <p v-if="showErr(i, 'emergency_contact')" class="field-error text-xs text-red-500 font-bold mt-2 flex items-center gap-1">
                    <span class="material-symbols-rounded text-[14px]">error</span>{{ showErr(i, 'emergency_contact') }}
                  </p>
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">เบอร์ฉุกเฉิน <span class="text-red-500">*</span></label>
                  <input v-model="p.emergency_phone" type="tel" required placeholder="0XXXXXXXXX"
                    inputmode="numeric" pattern="[0-9]{10}" maxlength="10"
                    @input="limitDigits(p, 'emergency_phone', 10)"
                    class="w-full border-2 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                    :class="showErr(i, 'emergency_phone') ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'" />
                  <p v-if="showErr(i, 'emergency_phone')" class="field-error text-xs text-red-500 font-bold mt-2 flex items-center gap-1">
                    <span class="material-symbols-rounded text-[14px]">error</span>{{ showErr(i, 'emergency_phone') }}
                  </p>
                </div>
                <div class="md:col-span-2">
                  <label class="block text-sm font-bold text-gray-700 mb-2">ต้องการอาหารฮาลาล <span class="text-red-500">*</span></label>
                  <p class="text-xs text-gray-500 mb-3">เพื่อให้เราจัดเตรียมอาหารได้เหมาะสม รบกวนแจ้งว่าท่านต้องการอาหารฮาลาลหรือไม่</p>
                  <div class="flex gap-3">
                    <label class="flex-1 flex items-center gap-3 border-2 rounded-2xl px-4 py-3 cursor-pointer transition-all"
                      :class="p.halal_food === true ? 'border-teal-600 bg-teal-50' : showErr(i, 'halal_food') ? 'border-red-300 bg-red-50/40' : 'border-gray-200 bg-gray-50/50 hover:bg-gray-50'">
                      <input type="radio" :name="`halal_food_${i}`" :value="true" v-model="p.halal_food" required class="hidden" />
                      <span class="w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 transition-all"
                        :class="p.halal_food === true ? 'border-teal-600' : 'border-gray-300'">
                        <span v-if="p.halal_food === true" class="w-2.5 h-2.5 rounded-full bg-teal-600"></span>
                      </span>
                      <span class="text-sm font-bold" :class="p.halal_food === true ? 'text-teal-700' : 'text-gray-700'">ต้องการ</span>
                    </label>
                    <label class="flex-1 flex items-center gap-3 border-2 rounded-2xl px-4 py-3 cursor-pointer transition-all"
                      :class="p.halal_food === false ? 'border-gray-400 bg-gray-100' : showErr(i, 'halal_food') ? 'border-red-300 bg-red-50/40' : 'border-gray-200 bg-gray-50/50 hover:bg-gray-50'">
                      <input type="radio" :name="`halal_food_${i}`" :value="false" v-model="p.halal_food" required class="hidden" />
                      <span class="w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 transition-all"
                        :class="p.halal_food === false ? 'border-gray-500' : 'border-gray-300'">
                        <span v-if="p.halal_food === false" class="w-2.5 h-2.5 rounded-full bg-gray-500"></span>
                      </span>
                      <span class="text-sm font-bold" :class="p.halal_food === false ? 'text-gray-700' : 'text-gray-700'">ไม่จำเป็น</span>
                    </label>
                  </div>
                  <p v-if="showErr(i, 'halal_food')" class="field-error text-xs text-red-500 font-bold mt-2 flex items-center gap-1">
                    <span class="material-symbols-rounded text-[14px]">error</span>{{ showErr(i, 'halal_food') }}
                  </p>
                </div>

                <div class="md:col-span-2">
                  <label class="block text-sm font-bold text-gray-700 mb-2">การแพ้อาหาร / อื่นๆ <span class="text-red-500">*</span></label>
                  <input v-model="p.allergies" type="text" required placeholder="เช่น แพ้อาหารทะเล, ไม่ทานเนื้อ หรือ ไม่มี"
                    class="w-full border-2 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                    :class="showErr(i, 'allergies') ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'" />
                  <p v-if="showErr(i, 'allergies')" class="field-error text-xs text-red-500 font-bold mt-2 flex items-center gap-1">
                    <span class="material-symbols-rounded text-[14px]">error</span>{{ showErr(i, 'allergies') }}
                  </p>
                </div>

                <div class="md:col-span-2">
                  <label class="block text-sm font-bold text-gray-700 mb-2">หมายเหตุสุขภาพ <span class="text-red-500">*</span></label>
                  <textarea v-model="p.health_notes" rows="2" required placeholder="แพ้ยา, โรคประจำตัว หรือ ไม่มี"
                    class="w-full border-2 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white resize-none"
                    :class="showErr(i, 'health_notes') ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'"></textarea>
                  <p v-if="showErr(i, 'health_notes')" class="field-error text-xs text-red-500 font-bold mt-2 flex items-center gap-1">
                    <span class="material-symbols-rounded text-[14px]">error</span>{{ showErr(i, 'health_notes') }}
                  </p>
                </div>
              </div>
            </div>

            <!-- Incomplete-fields summary -->
            <div v-if="attempted && incompletePassengers.length"
              class="mt-8 p-5 rounded-2xl bg-red-50 border border-red-200 text-sm">
              <p class="flex items-center gap-2 font-bold text-red-700 mb-2">
                <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 1">error</span>
                ยังกรอกข้อมูลไม่ครบ กรุณาตรวจสอบช่องที่ทำเครื่องหมายสีแดง
              </p>
              <ul class="space-y-3 text-red-600 font-medium">
                <li v-for="item in incompletePassengers" :key="item.index" class="flex items-start gap-2">
                  <span class="material-symbols-rounded text-[16px] mt-0.5">person</span>
                  <div class="min-w-0">
                    <button type="button" @click="scrollToPassenger(item.index)" class="text-left font-bold text-red-700 hover:underline">
                      ผู้เดินทางคนที่ {{ item.index + 1 }} — ยังขาดข้อมูล {{ item.count }} ช่อง
                    </button>
                    <ul class="mt-1 ml-1 space-y-0.5 text-[13px] text-red-500">
                      <li v-for="(msg, field) in passengerErrors[item.index]" :key="field" class="flex items-start gap-1.5">
                        <span class="material-symbols-rounded text-[13px] mt-0.5">chevron_right</span>
                        <span>{{ msg }}</span>
                      </li>
                    </ul>
                  </div>
                </li>
              </ul>
            </div>

            <div class="mt-8 flex flex-col sm:flex-row gap-4 justify-end">
              <button @click="step = hasSeatMap ? (isTrekking ? 1 : 0) : (isTrekking ? 0 : -1); if (step === -1) $router.push('/trips')"
                class="w-full sm:w-auto flex items-center justify-center gap-2 bg-white border-2 border-gray-200 text-gray-700 px-8 py-4 rounded-2xl font-bold text-base hover:bg-gray-50 hover:border-gray-300 active:scale-95 transition-all duration-300">
                <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">arrow_back</span>
                ย้อนกลับ
              </button>
              <button @click="goToSummary"
                class="w-full sm:w-auto bg-teal-600 text-white px-8 py-4 rounded-2xl font-bold text-base hover:bg-teal-700 active:scale-95 transition-all duration-300 shadow-lg shadow-teal-600/20 flex items-center justify-center gap-2"
                :class="{ 'opacity-60': attempted && !isPassengerValid }">
                <span>ดูสรุปการจอง</span>
                <span class="material-symbols-rounded" style="font-variation-settings:'FILL' 0,'wght' 400">arrow_forward</span>
              </button>
            </div>
          </div>

          <!-- Step 2/3: Summary -->
          <div v-if="step === (isTrekking ? 3 : 2)">
            <div class="mb-8 bg-white p-6 md:p-8 rounded-[2rem] shadow-sm border border-gray-100">
              <div class="flex items-center gap-3 mb-2">
                <div class="w-8 h-8 rounded-full bg-teal-100 text-teal-600 flex items-center justify-center">
                  <span class="material-symbols-rounded text-lg">fact_check</span>
                </div>
                <h2 class="text-2xl font-bold text-gray-900">ตรวจสอบความถูกต้อง</h2>
              </div>
              <p class="text-gray-500">กรุณาตรวจสอบรายละเอียดการจองอีกครั้งก่อนทำการชำระเงิน</p>
            </div>

            <CountdownTimer v-if="seatsStore.countdownSeconds > 0"
              :seconds="seatsStore.countdownSeconds" class="mb-8" />

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
              <div class="flex flex-col gap-6">
                <!-- Trip Details Card -->
                <div class="bg-white border border-gray-100 rounded-[2rem] p-6 md:p-8 shadow-sm">
                  <h3 class="font-bold text-gray-900 text-lg mb-6 flex items-center gap-3">
                    <span class="material-symbols-rounded text-teal-600">confirmation_number</span>
                    รายละเอียดการจอง
                  </h3>
                  
                  <div class="space-y-4">
                    <div class="flex justify-between items-start py-3 border-b border-gray-50">
                      <span class="text-gray-500 text-sm font-medium">ชื่อทริป</span>
                      <span class="text-gray-900 text-sm font-bold text-right max-w-[200px]">{{ schedule.trip?.title }}</span>
                    </div>
                    <div class="flex justify-between items-center py-3 border-b border-gray-50">
                      <span class="text-gray-500 text-sm font-medium">วันเดินทาง</span>
                      <span class="text-gray-900 text-sm font-bold">{{ formatDate(schedule.departure_date) }}</span>
                    </div>
                    <div v-if="selectedPickup" class="flex justify-between items-start py-3 border-b border-gray-50">
                      <span class="text-gray-500 text-sm font-medium">จุดขึ้นรถ</span>
                      <div class="text-right">
                        <p class="text-gray-900 text-sm font-bold">{{ selectedPickup.pickup_location }}</p>
                        <p class="text-[10px] text-teal-600 font-bold uppercase tracking-wider">{{ selectedPickup.region_label }}</p>
                      </div>
                    </div>
                    <div v-if="hasSeatMap" class="flex justify-between items-center py-3">
                      <span class="text-gray-500 text-sm font-medium">ที่นั่งที่เลือก</span>
                      <span class="px-3 py-1 bg-teal-50 text-teal-700 text-sm font-black rounded-lg border border-teal-100 italic tracking-widest">{{ seatsStore.selectedSeatIds.join(', ') }}</span>
                    </div>
                  </div>
                </div>

                <!-- Promo Code Card -->
                <div class="bg-white border border-gray-100 rounded-[2rem] p-6 shadow-sm">
                  <h3 class="font-bold text-gray-900 text-lg mb-4 flex items-center gap-2">
                    <span class="material-symbols-rounded text-teal-600">local_offer</span>
                    โค้ดส่วนลด / โปรโมชั่น
                  </h3>
                  
                  <div v-if="!promotionData" class="flex flex-col gap-2">
                    <div class="flex gap-2">
                      <input v-model="promotionInput" type="text" placeholder="กรอกโค้ดส่วนลด (ถ้ามี)"
                        class="flex-1 border-2 border-gray-200 rounded-xl px-4 py-3 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all uppercase"
                        @keyup.enter="applyPromotion" />
                      <button @click="applyPromotion" :disabled="promotionLoading || !promotionInput.trim()"
                        class="bg-gray-900 text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-gray-800 disabled:opacity-50 transition-all flex items-center justify-center gap-2 shrink-0">
                        <span v-if="promotionLoading" class="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                        <span>ใช้งาน</span>
                      </button>
                    </div>
                    <p v-if="promotionError" class="text-red-500 text-xs font-bold mt-1">{{ promotionError }}</p>
                  </div>
                  
                  <div v-else class="bg-teal-50 border border-teal-100 rounded-xl p-4 flex items-center justify-between">
                    <div>
                      <div class="flex items-center gap-2 mb-1">
                        <span class="material-symbols-rounded text-teal-600 text-[18px]">check_circle</span>
                        <span class="font-bold text-teal-800 text-sm">โค้ด {{ promotionCode }} ถูกใช้งานแล้ว</span>
                      </div>
                      <p class="text-xs text-teal-600 font-medium">ได้รับส่วนลด ฿{{ discountAmount.toLocaleString() }}</p>
                    </div>
                    <button @click="removePromotion" class="text-gray-400 hover:text-red-500 transition-colors p-2">
                      <span class="material-symbols-rounded text-xl">close</span>
                    </button>
                  </div>
                </div>
              </div>

              <!-- Price Card -->
              <div class="bg-teal-700 text-white rounded-[2rem] p-6 md:p-8 shadow-xl shadow-teal-900/10 relative overflow-hidden flex flex-col justify-between">
                <!-- Background Decoration -->
                <div class="absolute -right-10 -top-10 w-40 h-40 bg-white/10 rounded-full blur-3xl"></div>
                <div class="absolute -left-10 -bottom-10 w-32 h-32 bg-teal-500/20 rounded-full blur-2xl"></div>

                <div class="relative z-10">
                  <h3 class="font-bold text-white/90 text-lg mb-6 flex items-center gap-3">
                    <span class="material-symbols-rounded">payments</span>
                    สรุปค่าใช้จ่าย
                  </h3>
                  
                  <div class="space-y-3 mb-8">
                    <template v-if="!hasVariedPickupPrices">
                      <div class="flex justify-between text-sm">
                        <span class="text-white/70">ราคาต่อที่นั่ง</span>
                        <span class="font-bold">฿{{ Number(effectivePrice).toLocaleString() }}</span>
                      </div>
                      <div class="flex justify-between text-sm">
                        <span class="text-white/70">จำนวนผู้เดินทาง</span>
                        <span class="font-bold">{{ passengers.length }} คน</span>
                      </div>
                    </template>
                    <template v-else>
                      <div v-for="(p, i) in passengers" :key="i" class="flex justify-between text-sm">
                        <span class="text-white/70">คนที่ {{ i + 1 }}{{ p.nickname ? ` (${p.nickname})` : '' }}</span>
                        <span class="font-bold">฿{{ passengerTicketPrice(p).toLocaleString() }}</span>
                      </div>
                    </template>
                    <div v-for="addon in selectedAddonItems" :key="addon.index" class="flex justify-between items-center text-sm text-amber-100">
                      <span class="flex items-center gap-2 min-w-0">
                        <img v-if="addon.image_url" :src="addon.image_url" :alt="addon.name" class="w-7 h-7 rounded-md object-cover shrink-0 border border-white/20" />
                        <span v-else class="material-symbols-rounded text-[16px] shrink-0">add_circle</span>
                        <span class="truncate">{{ addon.name }}</span>
                      </span>
                      <span class="font-bold shrink-0">+฿{{ addonLineTotal(addon).toLocaleString() }}</span>
                    </div>
                    <div v-if="promotionData" class="flex justify-between text-sm text-teal-200 pt-2 border-t border-white/20">
                      <span class="flex items-center gap-1">
                        <span class="material-symbols-rounded text-[16px]">local_offer</span>
                        ส่วนลด ({{ promotionCode }})
                      </span>
                      <span class="font-bold">-฿{{ discountAmount.toLocaleString() }}</span>
                    </div>
                  </div>
                </div>

                <div class="relative z-10 pt-6 border-t border-white/10">
                  <p class="text-xs text-white/60 font-bold uppercase tracking-widest mb-1">ยอดรวมสุทธิ</p>
                  <div class="flex items-baseline gap-1">
                    <span class="text-xl font-bold">฿</span>
                    <span class="text-5xl font-black font-anuphan tracking-tighter">{{ totalAmount.toLocaleString() }}</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Passenger Summary List -->
            <div class="bg-white border border-gray-100 rounded-[2rem] p-6 md:p-8 mb-8 shadow-sm">
              <h3 class="font-bold text-gray-900 text-lg mb-6 flex items-center gap-3">
                <span class="material-symbols-rounded text-teal-600">group</span>
                รายชื่อผู้เดินทาง ({{ passengers.length }})
              </h3>
              
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div v-for="(p, i) in passengers" :key="i"
                  class="flex items-center gap-4 p-4 rounded-2xl bg-gray-50 border border-gray-100 hover:border-teal-200 hover:bg-teal-50/30 transition-all duration-300">
                  <div class="w-10 h-10 rounded-xl bg-teal-600 text-white flex items-center justify-center text-sm font-black shadow-lg shadow-teal-600/20 shrink-0 italic">
                    {{ i + 1 }}
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="font-bold text-gray-900 text-sm truncate">{{ p.title }}{{ p.name }} <span v-if="p.nickname" class="text-teal-600 ml-1">({{ p.nickname }})</span></p>
                    <div class="flex flex-wrap gap-2 mt-1">
                      <span v-if="hasSeatMap && seatsStore.selectedSeats[i]" class="text-[10px] bg-white border border-gray-200 text-gray-600 px-2 py-0.5 rounded-full font-bold">ที่นั่ง {{ seatsStore.selectedSeats[i].id }}</span>
                      <span class="text-[10px] bg-white border border-gray-200 text-gray-600 px-2 py-0.5 rounded-full font-bold">กรุ๊ปเลือด {{ p.blood_group || '-' }}</span>
                      <span v-if="pickupPoints.length && p.pickup_point_id" class="text-[10px] bg-teal-50 border border-teal-100 text-teal-700 px-2 py-0.5 rounded-full font-bold flex items-center gap-1">
                        <span class="material-symbols-rounded text-[11px]">location_on</span>
                        {{ pickupPoints.find(pt => pt.id === p.pickup_point_id)?.pickup_location || '-' }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="flex flex-col sm:flex-row gap-4">
              <button @click="step = hasSeatMap ? (isTrekking ? 2 : 1) : (isTrekking ? 1 : 0)"
                class="flex items-center justify-center gap-2 text-gray-500 hover:text-gray-900 px-8 py-4 rounded-2xl font-bold text-base transition-all">
                <span class="material-symbols-rounded text-[20px]">arrow_back</span>
                ย้อนกลับ
              </button>
              <button @click="createBooking"
                :disabled="bookingLoading"
                class="flex-1 flex items-center justify-center gap-3 bg-emerald-600 text-white px-8 py-5 rounded-2xl font-black text-xl hover:bg-emerald-700 active:scale-[0.98] transition-all duration-300 shadow-xl shadow-emerald-600/30 disabled:opacity-50 group">
                <span v-if="bookingLoading" class="w-6 h-6 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                <template v-else>
                  <span class="material-symbols-rounded text-2xl group-hover:rotate-12 transition-transform">verified</span>
                  <span>{{ bookingLoading ? 'กำลังสร้างการจอง...' : 'ยืนยันและชำระเงิน' }}</span>
                </template>
              </button>
            </div>
            
            <p v-if="bookingError" class="flex items-center gap-2 text-red-600 text-sm mt-6 p-4 bg-red-50 border border-red-100 rounded-2xl animate-in shake duration-500">
              <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 1,'wght' 400">error</span>
              {{ bookingError }}
            </p>
          </div>
        </div>

        <!-- Right: Booking Panel (Sidebar) -->
        <aside class="lg:col-span-5 xl:col-span-4 sticky transition-all duration-300 space-y-6 z-30"
          :class="[
            seatsStore.hasActiveBooking ? 'top-[140px]' : 'top-[100px]',
            'lg:block'
          ]" v-if="!isTrekking || step > 0">

          <!-- Summary Card -->
          <div class="bg-white rounded-[2rem] p-6 shadow-sm border border-gray-100 overflow-hidden relative">
            <h2 class="text-xl font-bold mb-6 flex items-center gap-2 text-gray-900">
              <span class="material-symbols-rounded text-teal-600">receipt_long</span>
              สรุปรายการจอง
            </h2>

            <!-- Selected seats (when on step 0/1 seatmap) -->
            <div v-if="hasSeatMap && seatsStore.hasSelectedSeats" class="mb-5 animate-in fade-in zoom-in-95 duration-300">
              <div class="flex justify-between items-center p-5 rounded-2xl bg-teal-50 border border-teal-100 shadow-sm">
                <div>
                  <p class="text-[11px] font-bold text-teal-700 uppercase tracking-wider mb-1 bg-teal-100 px-2 py-0.5 rounded-full inline-block">ที่นั่งที่เลือก</p>
                  <p class="text-2xl font-black text-teal-900 mt-1">{{ seatsStore.selectedSeatIds.join(', ') }}</p>
                </div>
                <div class="w-12 h-12 rounded-2xl bg-teal-600 flex items-center justify-center text-white shadow-lg shadow-teal-600/30">
                  <span class="material-symbols-rounded text-[28px]">airline_seat_recline_extra</span>
                </div>
              </div>
            </div>

            <!-- Selected region info -->
            <div v-if="selectedPickup" class="mb-6 p-4 rounded-3xl bg-gray-50 border border-gray-100">
              <p class="text-[11px] font-bold text-gray-500 uppercase tracking-[0.1em] mb-3">จุดขึ้นรถ</p>
              <div class="flex items-start gap-3">
                <span class="material-symbols-rounded text-emerald-600 text-xl">location_on</span>
                <div>
                  <p class="text-sm font-bold text-gray-900 leading-tight">{{ selectedPickup.pickup_location }}</p>
                  <p class="text-[11px] text-emerald-700 font-bold mt-1 bg-emerald-100 px-2 py-0.5 rounded-full w-fit">ภูมิภาค: {{ selectedPickup.region_label }}</p>
                  
                  <a v-if="selectedPickup.map_url" :href="selectedPickup.map_url" target="_blank"
                    class="mt-3 inline-flex items-center gap-1.5 text-[11px] font-bold text-teal-600 hover:text-teal-700 bg-white border border-gray-200 px-3 py-1.5 rounded-lg transition-all shadow-sm active:scale-95">
                    <span class="material-symbols-rounded text-[16px]">map</span>
                    เปิด Google Maps
                  </a>
                </div>
              </div>
            </div>

            <!-- Price Breakdown -->
            <div class="space-y-4 mb-8 bg-gray-50 rounded-3xl p-5 border border-gray-100">
              <div class="flex justify-between items-center text-sm">
                <span class="text-gray-500 font-medium">ราคาต่อคน</span>
                <span class="text-gray-900 font-bold">฿{{ Number(effectivePrice).toLocaleString() }}</span>
              </div>
              <div class="flex justify-between items-center text-sm">
                <span class="text-gray-500 font-medium">จำนวนผู้ร่วมเดินทาง</span>
                <span class="text-gray-900 font-bold">{{ seatCount }} คน</span>
              </div>
              <div v-for="addon in selectedAddonItems" :key="addon.index" class="flex justify-between items-center text-sm text-amber-700">
                <span class="font-bold flex items-center gap-2 min-w-0">
                  <img v-if="addon.image_url" :src="addon.image_url" :alt="addon.name" class="w-7 h-7 rounded-md object-cover shrink-0 border border-amber-100" />
                  <span v-else class="material-symbols-rounded text-[16px] shrink-0">add_circle</span>
                  <span class="truncate">{{ addon.name }}</span>
                </span>
                <span class="font-bold shrink-0">+฿{{ addonLineTotal(addon).toLocaleString() }}</span>
              </div>
              
              <div v-if="promotionData" class="flex justify-between items-center text-sm text-teal-600">
                <span class="font-bold flex items-center gap-1">
                  <span class="material-symbols-rounded text-[16px]">local_offer</span>
                  ส่วนลด
                </span>
                <span class="font-bold">-฿{{ discountAmount.toLocaleString() }}</span>
              </div>
              
              <div class="pt-5 border-t border-dashed border-gray-300">
                <div class="flex justify-between items-end mb-1">
                  <span class="text-sm font-bold text-gray-500 mb-1">ยอดรวมสุทธิ</span>
                  <div class="text-right">
                    <span class="text-4xl font-extrabold text-teal-700 font-anuphan tracking-tighter">
                      <span class="text-2xl text-teal-600 mr-0.5">฿</span>{{ totalAmount.toLocaleString() }}
                    </span>
                  </div>
                </div>
                <p class="text-[10px] text-gray-400 text-right font-medium">รวมภาษีและค่าบริการแล้ว</p>
              </div>
            </div>

            <!-- Promo Code -->
            <div v-if="step !== (isTrekking ? 3 : 2)" class="mb-8 rounded-3xl border border-teal-100 bg-teal-50/60 p-5">
              <h3 class="mb-4 flex items-center gap-2 text-sm font-black text-teal-800">
                <span class="material-symbols-rounded text-[18px]">local_offer</span>
                โค้ดส่วนลด / โปรโมชั่น
              </h3>

              <div v-if="!promotionData" class="space-y-2">
                <div class="flex gap-2">
                  <input v-model="promotionInput" type="text" placeholder="กรอกโค้ดส่วนลด"
                    class="min-w-0 flex-1 rounded-xl border-2 border-white bg-white px-4 py-3 text-sm font-bold uppercase text-gray-900 outline-none transition-all placeholder:font-medium placeholder:text-gray-400 focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10"
                    @keyup.enter="applyPromotion" />
                  <button @click="applyPromotion" :disabled="promotionLoading || !promotionInput.trim()"
                    class="flex shrink-0 items-center justify-center gap-2 rounded-xl bg-teal-700 px-4 py-3 text-sm font-black text-white transition-all hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50">
                    <span v-if="promotionLoading" class="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                    <span>ใช้</span>
                  </button>
                </div>
                <p v-if="promotionError" class="text-xs font-bold text-red-500">{{ promotionError }}</p>
              </div>

              <div v-else class="flex items-center justify-between gap-3 rounded-2xl border border-teal-200 bg-white p-4">
                <div class="min-w-0">
                  <div class="mb-1 flex items-center gap-2">
                    <span class="material-symbols-rounded text-[18px] text-teal-600">check_circle</span>
                    <span class="truncate text-sm font-black text-teal-800">ใช้โค้ด {{ promotionCode }} แล้ว</span>
                  </div>
                  <p class="text-xs font-bold text-teal-600">ส่วนลด ฿{{ discountAmount.toLocaleString() }}</p>
                </div>
                <button @click="removePromotion" class="shrink-0 rounded-full p-2 text-gray-400 transition-colors hover:bg-red-50 hover:text-red-500">
                  <span class="material-symbols-rounded text-xl">close</span>
                </button>
              </div>
            </div>

            <!-- CTA Button Section -->
            <div class="space-y-4">
              <!-- Seat selection confirmed, go to passenger info -->
              <button v-if="step === (isTrekking ? 1 : 0) && hasSeatMap"
                @click="lockAndNext"
                :disabled="!seatsStore.hasSelectedSeats || lockingSeats"
                class="w-full bg-teal-600 text-white py-4 rounded-2xl font-black text-lg hover:bg-teal-700 active:scale-[0.98] transition-all duration-300 shadow-xl shadow-teal-600/30 disabled:opacity-50 disabled:grayscale disabled:cursor-not-allowed flex items-center justify-center gap-3 group">
                <span v-if="lockingSeats" class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                <span v-else class="material-symbols-rounded transition-transform group-hover:scale-110">lock_outline</span>
                <span>{{ lockingSeats ? 'กำลังตรวจสอบที่นั่ง...' : 'ไปกรอกข้อมูลผู้จอง' }}</span>
              </button>

              <!-- Passenger info confirmed, go to summary step -->
              <button v-else-if="(step === (isTrekking ? 1 : 0) && !hasSeatMap) || step === (isTrekking ? 2 : 1)"
                @click="goToSummary"
                class="w-full bg-teal-600 text-white py-4 rounded-2xl font-black text-lg hover:bg-teal-700 active:scale-[0.98] transition-all duration-300 shadow-xl shadow-teal-600/30 flex items-center justify-center gap-3"
                :class="{ 'opacity-60': attempted && !isPassengerValid }">
                <span class="material-symbols-rounded">fact_check</span>
                <span>ดูสรุปการจอง</span>
                <span class="material-symbols-rounded animate-bounce-x">arrow_forward</span>
              </button>

              <!-- Final confirmation button -->
              <button v-else-if="step === (isTrekking ? 3 : 2)"
                @click="createBooking"
                :disabled="bookingLoading"
                class="w-full bg-emerald-600 text-white py-4 rounded-2xl font-black text-lg hover:bg-emerald-700 active:scale-[0.98] transition-all duration-300 shadow-xl shadow-emerald-600/30 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-3">
                <span v-if="bookingLoading" class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                <span v-else class="material-symbols-rounded">payment</span>
                <span>{{ bookingLoading ? 'กำลังสร้างการจอง...' : 'ยืนยันและชำระเงิน' }}</span>
              </button>
            </div>

            <!-- Validation Feedback -->
            <div v-if="step === (isTrekking ? 1 : 0) && hasSeatMap && !seatsStore.hasSelectedSeats" class="mt-4 p-3 rounded-xl bg-amber-50 border border-amber-100 text-[11px] text-amber-700 font-bold flex items-center gap-2">
              <span class="material-symbols-rounded text-base">info</span>
              กรุณาเลือกที่นั่งในแผนผังเพื่อดำเนินการต่อ
            </div>

            <div class="mt-6 flex items-center justify-center gap-2 text-gray-400 group">
              <span class="material-symbols-rounded text-sm">verified_user</span>
              <span class="text-[11px] font-bold group-hover:text-teal-600 transition-colors tracking-wide uppercase">การชำระเงินที่ปลอดภัยและการเข้ารหัส SSL</span>
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
          <div class="p-8">
            <div class="space-y-6 max-h-[50vh] overflow-y-auto pr-2 scrollbar-thin scrollbar-thumb-teal-200">
              <div class="flex gap-4">
                <div class="w-10 h-10 rounded-xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100">
                  <span class="material-symbols-rounded text-teal-600">health_and_safety</span>
                </div>
                <div class="flex-1">
                  <h4 class="font-bold text-gray-900 text-sm mb-1">การเสียชีวิตและทุพพลภาพ</h4>
                  <p class="text-sm text-gray-600 leading-relaxed">การเสียชีวิต การสูญเสียอวัยวะ สายตา หรือทุพพลภาพถาวรสิ้นเชิงเนื่องจากอุบัติเหตุ <span class="font-bold text-teal-700">1,000,000 บาท</span></p>
                </div>
              </div>
              <div class="flex gap-4">
                <div class="w-10 h-10 rounded-xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100">
                  <span class="material-symbols-rounded text-teal-600">medical_services</span>
                </div>
                <div class="flex-1">
                  <h4 class="font-bold text-gray-900 text-sm mb-1">ค่ารักษาพยาบาลจากอุบัติเหตุ</h4>
                  <p class="text-sm text-gray-600 leading-relaxed">การรักษาพยาบาลเนื่องจากการบาดเจ็บจากอุบัติเหตุ <span class="font-bold text-teal-700">500,000 บาท</span></p>
                </div>
              </div>
              <div class="flex gap-4">
                <div class="w-10 h-10 rounded-xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100">
                  <span class="material-symbols-rounded text-teal-600">gavel</span>
                </div>
                <div class="flex-1">
                  <h4 class="font-bold text-gray-900 text-sm mb-1">ความรับผิดชอบต่อบุคคลภายนอก</h4>
                  <p class="text-sm text-gray-600 leading-relaxed">ความรับผิดชอบตามกฎหมายต่อบุคคลภายนอก <span class="font-bold text-teal-700">200,000 บาท</span></p>
                </div>
              </div>
              <div class="flex gap-4">
                <div class="w-10 h-10 rounded-xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100">
                  <span class="material-symbols-rounded text-teal-600">restaurant</span>
                </div>
                <div class="flex-1">
                  <h4 class="font-bold text-gray-900 text-sm mb-1">ค่ารักษาอาหารเป็นพิษ</h4>
                  <p class="text-sm text-gray-600 leading-relaxed">ค่ารักษาพยาบาลอันเกิดจากโรคอาหารเป็นพิษ <span class="font-bold text-teal-700">5,000 บาท</span></p>
                </div>
              </div>
              <div class="flex gap-4">
                <div class="w-10 h-10 rounded-xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100">
                  <span class="material-symbols-rounded text-teal-600">emergency_share</span>
                </div>
                <div class="flex-1">
                  <h4 class="font-bold text-gray-900 text-sm mb-1">การเคลื่อนย้ายฉุกเฉิน</h4>
                  <p class="text-sm text-gray-600 leading-relaxed">การเคลื่อนย้ายเพื่อการรักษาพยาบาลฉุกเฉินหรือการเคลื่อนย้ายกลับประเทศเนื่องจากการบาดเจ็บจากอุบัติเหตุ <span class="font-bold text-teal-700">100,000 บาท</span></p>
                </div>
              </div>
              <div class="flex gap-4">
                <div class="w-10 h-10 rounded-xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100">
                  <span class="material-symbols-rounded text-teal-600">flight_takeoff</span>
                </div>
                <div class="flex-1">
                  <h4 class="font-bold text-gray-900 text-sm mb-1">การส่งศพหรืออัฐิกลับประเทศ</h4>
                  <p class="text-sm text-gray-600 leading-relaxed">ค่าใช้จ่ายในการส่งศพหรืออัฐิกลับประเทศเนื่องจากการเสียชีวิตจากอุบัติเหตุ <span class="font-bold text-teal-700">100,000 บาท</span></p>
                </div>
              </div>
            </div>
            
            <button @click="showInsuranceModal = false" class="w-full bg-teal-600 text-white font-bold py-4 rounded-2xl hover:bg-teal-700 active:scale-[0.98] transition-all shadow-lg shadow-teal-600/20 mt-8">
              รับทราบ
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Sticky Mobile Bottom Bar -->
    <div class="lg:hidden fixed bottom-0 left-0 right-0 bg-white border-t border-gray-100 p-4 pb-safe z-50 shadow-[0_-10px_20px_rgba(0,0,0,0.05)] animate-in slide-in-from-bottom duration-500">
      <div class="flex items-center justify-between gap-4 max-w-lg mx-auto">
        <div @click="step === (isTrekking ? 3 : 2) ? null : window.scrollTo({top: 0, behavior: 'smooth'})" class="cursor-pointer">
          <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">ยอดรวมสุทธิ</p>
          <p class="text-xl font-black text-teal-700 font-anuphan tracking-tighter">
            <span class="text-sm mr-0.5">฿</span>{{ totalAmount.toLocaleString() }}
          </p>
        </div>
        
        <button v-if="step === (isTrekking ? 1 : 0) && hasSeatMap"
          @click="lockAndNext"
          :disabled="!seatsStore.hasSelectedSeats || lockingSeats"
          class="flex-1 bg-teal-600 text-white py-3.5 rounded-2xl font-bold text-sm hover:bg-teal-700 active:scale-95 transition-all shadow-lg shadow-teal-600/20 disabled:opacity-50 disabled:grayscale flex items-center justify-center gap-2">
          <span>{{ lockingSeats ? 'ล็อคที่นั่ง...' : 'ไปเลือกที่นั่ง' }}</span>
          <span class="material-symbols-rounded text-lg">arrow_forward</span>
        </button>

        <button v-else-if="(step === (isTrekking ? 1 : 0) && !hasSeatMap) || step === (isTrekking ? 2 : 1)"
          @click="goToSummary"
          class="flex-1 bg-teal-600 text-white py-3.5 rounded-2xl font-bold text-sm hover:bg-teal-700 active:scale-95 transition-all shadow-lg shadow-teal-600/20 flex items-center justify-center gap-2"
          :class="{ 'opacity-60': attempted && !isPassengerValid }">
          <span>ดูสรุปการจอง</span>
          <span class="material-symbols-rounded text-lg">arrow_forward</span>
        </button>

        <button v-else-if="step === (isTrekking ? 3 : 2)"
          @click="createBooking"
          :disabled="bookingLoading"
          class="flex-1 bg-emerald-600 text-white py-3.5 rounded-2xl font-bold text-sm hover:bg-emerald-700 active:scale-95 transition-all shadow-lg shadow-emerald-600/20 disabled:opacity-50 flex items-center justify-center gap-2">
          <span>{{ bookingLoading ? 'กำลังสร้าง...' : 'ชำระเงิน' }}</span>
          <span class="material-symbols-rounded text-lg">payment</span>
        </button>

        <button v-else-if="isTrekking && step === 0"
          @click="confirmRegion"
          :disabled="!selectedPickup && !customPickup && pickupPoints.length > 0"
          class="flex-1 bg-teal-600 text-white py-3.5 rounded-2xl font-bold text-sm hover:bg-teal-700 active:scale-95 transition-all shadow-lg shadow-teal-600/20 disabled:opacity-50 disabled:grayscale flex items-center justify-center gap-2">
          <span>ไปเลือกที่นั่ง</span>
          <span class="material-symbols-rounded text-lg">arrow_forward</span>
        </button>
      </div>
    </div>

    <CustomPickupModal
      v-if="showCustomPickupModal"
      :center-lat="pickupMapCenter.lat"
      :center-lng="pickupMapCenter.lng"
      :initial="customPickup"
      @confirm="onCustomPickupConfirm"
      @close="showCustomPickupModal = false"
    />
</div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onBeforeUnmount, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import api from '../lib/axios';
import { useSeatsStore } from '../stores/seats';
import { useBookingStore } from '../stores/booking';
import SeatMap from '../components/SeatMap.vue';
import CountdownTimer from '../components/CountdownTimer.vue';
import CustomPickupModal from '../components/CustomPickupModal.vue';
import Swal from 'sweetalert2';
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
const vehicleTouchStartX = ref(0);
const bookingError = ref('');
const isJoinTrip = ref(false);

const vehicleTouchStart = (e) => {
  vehicleTouchStartX.value = e.touches[0].clientX;
};
const vehicleTouchEnd = (e) => {
  const diff = vehicleTouchStartX.value - e.changedTouches[0].clientX;
  const images = schedule.value?.vehicle?.images;
  if (!images || images.length <= 1) return;
  if (Math.abs(diff) < 40) return;
  if (diff > 0) {
    vehicleImageIndex.value = (vehicleImageIndex.value + 1) % images.length;
  } else {
    vehicleImageIndex.value = (vehicleImageIndex.value - 1 + images.length) % images.length;
  }
};
const passengerCount = ref(1);
const bookingFor = ref('self');
const isGroup = ref(false);
const groupName = ref('');
const groupNotes = ref('');
const showInsuranceModal = ref(false);
const selectedAddons = ref([]);
const addonZoomImage = ref(null);

const toggleAddon = (index) => {
  selectedAddons.value = selectedAddons.value.includes(index)
    ? selectedAddons.value.filter((i) => i !== index)
    : [...selectedAddons.value, index];
};

const promotionCode = ref('');
const promotionInput = ref('');
const promotionData = ref(null);
const promotionLoading = ref(false);
const promotionError = ref('');

const hasSeatMap = computed(() => {
  if (isJoinTrip.value) return false;
  return seatsStore.seatMap?.has_seat_map ?? false;
});
const isDiving = computed(() => ['diving', 'snorkeling'].includes(schedule.value?.trip?.type));
const isTrekking = computed(() => schedule.value?.trip?.type === 'trekking' && !isJoinTrip.value);
const scheduleAvailabilityPillClass = computed(() => {
  if (Number(schedule.value?.available_seats || 0) < 3) {
    return 'bg-red-50 text-red-600 border-red-200';
  }
  return 'bg-gray-50 text-gray-700 border-gray-200';
});
const maxPassengers = computed(() => {
  if (isJoinTrip.value) return 50; // Allow more for join trip
  return Math.min(schedule.value?.available_seats || 10, 10);
});

const preselectedRegion = route.query.region || null;
const pickupPoints = computed(() => {
  // Join-trip (Enjoy Trip) bookings don't pick a pickup point — travellers
  // arrange their own way to the meeting spot — so expose none. This cascades
  // to the picker UI, per-passenger selector, validation, and pricing.
  if (isJoinTrip.value) return [];
  const all = schedule.value?.pickup_points || [];
  if (!preselectedRegion) return all;
  const filtered = all.filter(pt => pt.region === preselectedRegion);
  return filtered.length ? filtered : all;
});
const selectedPickup = ref(null);
// จุดรับที่ลูกค้าปักหมุดเอง { label, lat, lng, note } — ใช้เมื่อไม่มีจุดที่กำหนดที่สะดวก
const customPickup = ref(null);
const showCustomPickupModal = ref(false);

// จุดเริ่มต้นแผนที่ = พิกัดจุดรับแรกของรอบ (ถ้ามี) เพื่อให้ลูกค้าเห็นบริเวณเส้นทาง
const pickupMapCenter = computed(() => {
  const withCoords = pickupPoints.value.find(pt => pt.latitude && pt.longitude);
  return withCoords
    ? { lat: Number(withCoords.latitude), lng: Number(withCoords.longitude) }
    : { lat: 13.7563, lng: 100.5018 };
});

function openCustomPickup() {
  showCustomPickupModal.value = true;
}

function onCustomPickupConfirm(payload) {
  customPickup.value = payload;
  selectedPickup.value = null; // จุด custom กับจุดที่กำหนดเลือกได้อย่างใดอย่างหนึ่ง
  showCustomPickupModal.value = false;
}

function clearCustomPickup() {
  customPickup.value = null;
}

const steps = computed(() => {
  if (isTrekking.value) {
    if (hasSeatMap.value) return ['เลือกจุดรับ', 'ผังที่นั่ง', 'ข้อมูลผู้เดินทาง', 'ชำระเงิน'];
    return ['เลือกจุดรับ', 'ข้อมูลผู้เดินทาง', 'ชำระเงิน'];
  }
  if (hasSeatMap.value) return ['ผังที่นั่ง', 'ข้อมูลผู้เดินทาง', 'ชำระเงิน'];
  return ['ข้อมูลผู้เดินทาง', 'ชำระเงิน'];
});

const effectivePrice = computed(() => {
  if (isJoinTrip.value && schedule.value?.join_trip_enabled) {
    return Number(schedule.value.join_trip_price || schedule.value.price || 0);
  }
  if (selectedPickup.value) return Number(selectedPickup.value.price);
  return Number(schedule.value?.price || 0);
});

const optionalAddons = computed(() => {
  const items = schedule.value?.trip?.must_know?.items || [];
  return items
    .map((item, index) => ({
      index,
      name: String(item?.name || '').trim(),
      price: Number(item?.price || 0),
      price_type: item?.price_type === 'per_person' ? 'per_person' : 'per_booking',
      image_url: String(item?.image_url || '').trim(),
    }))
    .filter((item) => item.name);
});

const passengers = ref([{
  title: '', name: '', nickname: '', id_card: '', birth_date: '', birth_day: '', birth_month: '', birth_year: '', phone: '', email: '', blood_group: '', allergies: '',
  health_notes: '', emergency_contact: '', emergency_phone: '',
  dive_cert_level: '', cert_number: '', weight: null, halal_food: null, pickup_point_id: null
}]);

const FORM_SESSION_KEY = computed(() => {
  const base = `booking_form_${route.params.scheduleId}`;
  return preselectedRegion ? `${base}_${preselectedRegion}` : base;
});

function saveFormData() {
  const scheduleId = route.params.scheduleId;
  if (!scheduleId) return;
  const data = {
    passengers: passengers.value,
    passengerCount: passengerCount.value,
    bookingFor: bookingFor.value,
    isGroup: isGroup.value,
    groupName: groupName.value,
    groupNotes: groupNotes.value,
    selectedPickupId: selectedPickup.value?.id ?? null,
    promotionCode: promotionCode.value,
    promotionData: promotionData.value,
    selectedAddons: selectedAddons.value,
  };
  sessionStorage.setItem(FORM_SESSION_KEY.value, JSON.stringify(data));
}

function restoreFormData() {
  const raw = sessionStorage.getItem(FORM_SESSION_KEY.value);
  if (!raw) return;
  try {
    const data = JSON.parse(raw);
    if (data.passengers && Array.isArray(data.passengers) && data.passengers.length > 0) {
      passengers.value = data.passengers;
      passengers.value.forEach(syncBirthParts);
      passengerCount.value = data.passengerCount ?? data.passengers.length;
    }
    bookingFor.value = data.bookingFor || 'self';
    isGroup.value = data.isGroup ?? false;
    groupName.value = data.groupName ?? '';
    groupNotes.value = data.groupNotes ?? '';
    promotionCode.value = data.promotionCode ?? '';
    promotionInput.value = data.promotionCode ?? '';
    promotionData.value = data.promotionData ?? null;
    selectedAddons.value = Array.isArray(data.selectedAddons) ? data.selectedAddons : [];
    if (data.selectedPickupId != null && pickupPoints.value.length > 0) {
      const pt = pickupPoints.value.find(p => p.id === data.selectedPickupId);
      if (pt) selectedPickup.value = pt;
    }
  } catch {}
}

function clearFormData() {
  sessionStorage.removeItem(FORM_SESSION_KEY.value);
}

watch(step, (newStep) => {
  seatsStore.saveStep(newStep);
});

watch(passengers, saveFormData, { deep: true });
watch([bookingFor, isGroup, groupName, groupNotes, passengerCount, selectedPickup, promotionCode, promotionData, selectedAddons], saveFormData);
watch(selectedAddons, saveFormData, { deep: true });

// When global pickup is auto-set (e.g. single-point trip on load), propagate to passengers that have no pickup chosen yet
watch(selectedPickup, (newPickup) => {
  if (!newPickup) return;
  passengers.value.forEach(p => {
    if (!p.pickup_point_id) p.pickup_point_id = newPickup.id;
  });
});

watch(passengerCount, (n) => {
  seatsStore.updateBookingDuration(n);
  while (passengers.value.length < n) {
    passengers.value.push({
      title: '', name: '', nickname: '', id_card: '', birth_date: '', birth_day: '', birth_month: '', birth_year: '', phone: '', email: '', blood_group: '', allergies: '',
      health_notes: '', emergency_contact: '', emergency_phone: '',
      dive_cert_level: '', cert_number: '', weight: null, halal_food: null,
      pickup_point_id: selectedPickup.value?.id ?? null
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
    birth_date: user.birth_date || '',
    phone: user.phone || '',
    email: user.email || '',
    blood_group: user.blood_group || '',
    emergency_contact: user.emergency_contact || '',
    emergency_phone: user.emergency_phone || '',
    allergies: user.allergies || '',
    health_notes: user.health_notes || '',
  };
  syncBirthParts(passengers.value[index]);

  if (!(isFemaleTrip && isMaleTitle)) {
    toast.success('ดึงข้อมูลจากโปรไฟล์สำเร็จ');
  }
}

// Today's date as a local YYYY-MM-DD string — caps the birth-date picker and
// rejects future dates (matches the backend's before:today rule).
const todayDate = (() => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
})();

// Buddhist-era birth-date dropdowns. The selects edit birth_day/birth_month/
// birth_year parts (year value stays C.E., displayed as พ.ศ. = +543), and
// updateBirth() recombines them into the ISO YYYY-MM-DD that the backend wants.
const thaiMonths = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
const birthYears = (() => {
  const cur = new Date().getFullYear();
  const arr = [];
  for (let y = cur; y >= 1900; y--) arr.push(y);
  return arr;
})();

function daysFor(p) {
  const m = Number(p.birth_month);
  const y = Number(p.birth_year) || 2000; // leap-year baseline keeps Feb 29 available until a year is chosen
  return m ? new Date(y, m, 0).getDate() : 31;
}

function updateBirth(p, part, value) {
  p[part] = value;
  // Clamp day when the chosen month/year can't hold it (e.g. 31 -> Feb).
  if (p.birth_day && Number(p.birth_day) > daysFor(p)) p.birth_day = '';
  const { birth_year: y, birth_month: m, birth_day: d } = p;
  p.birth_date = (y && m && d) ? `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}` : '';
}

// Populate the dropdown parts from an ISO birth_date (profile autofill / restore).
function syncBirthParts(p) {
  if (p && /^\d{4}-\d{2}-\d{2}$/.test(p.birth_date || '')) {
    const [y, m, d] = p.birth_date.split('-');
    p.birth_year = String(Number(y));
    p.birth_month = String(Number(m));
    p.birth_day = String(Number(d));
  }
}

const isValidEmail = (email) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || '').trim());
const isFriendEmailValid = computed(() => bookingFor.value !== 'friend' || isValidEmail(passengers.value[0]?.email));
const digitsOnly = (value) => String(value || '').replace(/\D/g, '');
const hasText = (value) => String(value || '').trim().length > 0;
const hasExactDigits = (value, length) => digitsOnly(value).length === length;

function limitDigits(passenger, field, length) {
  passenger[field] = digitsOnly(passenger[field]).slice(0, length);
}

// Per-passenger validation: returns { field: 'thai error message' } for every
// field that is missing or malformed. Drives both isPassengerValid and the
// inline error UI so they can never drift apart.
function computePassengerErrors(p, i) {
  const errors = {};
  const womenOnly = schedule.value?.trip?.is_women_only;
  if (!p.title) errors.title = 'กรุณาเลือกคำนำหน้า';
  else if (womenOnly && !['นาง', 'นางสาว'].includes(p.title)) errors.title = 'ทริปนี้สำหรับผู้หญิงเท่านั้น';
  if (!hasText(p.name)) errors.name = 'กรุณากรอกชื่อ-นามสกุล';
  if (!hasText(p.nickname)) errors.nickname = 'กรุณากรอกชื่อเล่น';
  if (!hasExactDigits(p.id_card, 13)) errors.id_card = 'กรุณากรอกเลขบัตรประชาชน 13 หลัก';
  if (!hasText(p.birth_date)) errors.birth_date = 'กรุณาเลือกวัน/เดือน/ปีเกิด';
  else if (p.birth_date >= todayDate) errors.birth_date = 'วัน/เดือน/ปีเกิดไม่ถูกต้อง';
  if (!hasExactDigits(p.phone, 10)) errors.phone = 'กรุณากรอกเบอร์โทรศัพท์ 10 หลัก';
  if (bookingFor.value === 'friend' && i === 0 && !isValidEmail(p.email)) errors.email = 'กรุณากรอกอีเมลของเพื่อนให้ถูกต้อง';
  if (!p.blood_group) errors.blood_group = 'กรุณาเลือกกรุ๊ปเลือด';
  if (p.halal_food === null || p.halal_food === undefined) errors.halal_food = 'กรุณาเลือกตัวเลือกอาหารฮาลาล';
  if (!hasText(p.emergency_contact)) errors.emergency_contact = 'กรุณากรอกผู้ติดต่อฉุกเฉิน';
  if (!hasExactDigits(p.emergency_phone, 10)) errors.emergency_phone = 'กรุณากรอกเบอร์ฉุกเฉิน 10 หลัก';
  if (!hasText(p.allergies)) errors.allergies = 'กรุณากรอกข้อมูลการแพ้อาหาร (หากไม่มีให้พิมพ์ "ไม่มี")';
  if (!hasText(p.health_notes)) errors.health_notes = 'กรุณากรอกหมายเหตุสุขภาพ (หากไม่มีให้พิมพ์ "ไม่มี")';
  // เมื่อผู้ใช้ปักหมุดจุดรับเอง (customPickup) ถือว่าเลือกจุดรับแล้ว ไม่ต้องบังคับจุดที่กำหนดไว้
  if (pickupPoints.value.length && !p.pickup_point_id && !customPickup.value) errors.pickup_point_id = 'กรุณาเลือกจุดขึ้นรถ';
  return errors;
}

const passengerErrors = computed(() => passengers.value.map((p, i) => computePassengerErrors(p, i)));
const isPassengerValid = computed(() => passengerErrors.value.every(e => Object.keys(e).length === 0));
const firstInvalidPassenger = computed(() => passengerErrors.value.findIndex(e => Object.keys(e).length > 0));
const incompletePassengers = computed(() =>
  passengerErrors.value
    .map((e, i) => ({ index: i, count: Object.keys(e).length }))
    .filter(x => x.count > 0)
);

// Whether the user has tried to advance — controls when "empty field" errors
// surface. Format errors on typed-in fields show immediately regardless.
const attempted = ref(false);
const liveErrorFields = ['id_card', 'phone', 'emergency_phone', 'email'];

// Returns the error message to display for a field, or '' when none should show.
function showErr(i, field) {
  const message = passengerErrors.value[i]?.[field];
  if (!message) return '';
  if (attempted.value) return message;
  // Before the first submit attempt, only nag about format on fields the user
  // has already started typing into.
  if (liveErrorFields.includes(field) && hasText(passengers.value[i]?.[field])) return message;
  return '';
}
const seatCount = computed(() => hasSeatMap.value ? seatsStore.selectedSeats.length || 1 : passengers.value.length);

const selectedAddonItems = computed(() => optionalAddons.value.filter((item) => selectedAddons.value.includes(item.index)));

const addonLineTotal = (item) => item.price * (item.price_type === 'per_person' ? seatCount.value : 1);

const addonsTotal = computed(() => selectedAddonItems.value.reduce((sum, item) => sum + addonLineTotal(item), 0));

const addonPriceTypeLabel = (item) => item.price_type === 'per_person' ? 'ต่อคน' : 'ครั้งเดียว';

const passengerTicketPrice = (p) => {
  if (pickupPoints.value.length && p.pickup_point_id) {
    const pt = pickupPoints.value.find(pp => pp.id === p.pickup_point_id);
    if (pt) return Number(pt.price);
  }
  return effectivePrice.value;
};

const passengersSubtotal = computed(() => {
  if (!pickupPoints.value.length) return effectivePrice.value * seatCount.value;
  return passengers.value.reduce((sum, p) => sum + passengerTicketPrice(p), 0);
});

const hasVariedPickupPrices = computed(() => {
  if (!pickupPoints.value.length || passengers.value.length <= 1) return false;
  const prices = passengers.value.map(p => passengerTicketPrice(p));
  return prices.some(price => price !== prices[0]);
});

const subtotalAmount = computed(() => passengersSubtotal.value + addonsTotal.value);

const discountAmount = computed(() => {
  if (!promotionData.value) return 0;
  if (promotionData.value.type === 'percent') {
    return subtotalAmount.value * (promotionData.value.value / 100);
  }
  return Number(promotionData.value.value);
});

const totalAmount = computed(() => Math.max(0, subtotalAmount.value - discountAmount.value));

async function applyPromotion() {
  if (!promotionInput.value.trim()) return;
  promotionLoading.value = true;
  promotionError.value = '';
  
  try {
    const res = await api.post('/promotions/validate', {
      code: promotionInput.value.trim(),
      trip_id: schedule.value?.trip?.id
    });
    
    if (res.data.valid) {
      promotionData.value = res.data.promotion;
      promotionCode.value = res.data.promotion.code;
      toast.success('ใช้โค้ดส่วนลดสำเร็จ');
    }
  } catch (e) {
    promotionData.value = null;
    promotionCode.value = '';
    promotionError.value = e.response?.data?.message || 'โค้ดส่วนลดไม่ถูกต้องหรือหมดอายุแล้ว';
  } finally {
    promotionLoading.value = false;
  }
}

function removePromotion() {
  promotionInput.value = '';
  promotionCode.value = '';
  promotionData.value = null;
  promotionError.value = '';
}

function formatDate(d) {
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

function selectRegion(pt) {
  selectedPickup.value = pt;
  customPickup.value = null; // เลือกจุดที่กำหนด → ยกเลิกจุดที่ปักเอง
}

function confirmRegion() {
  // Pre-fill all passengers' pickup_point_id with the global selection
  if (selectedPickup.value) {
    passengers.value.forEach(p => { p.pickup_point_id = selectedPickup.value.id; });
  }
  // Move to seat map step (step 1) or passenger step (step 1) — step 0 is region
  if (!hasSeatMap.value) {
    seatsStore.startManualCountdown(
      schedule.value?.trip?.title || 'กิจกรรม',
      route.params.scheduleId,
      selectedPickup.value?.region || preselectedRegion,
      passengerCount.value
    );
  }
  step.value = 1;
}

function skipRegionStep() {
  selectedPickup.value = null;
  if (!hasSeatMap.value) {
    seatsStore.startManualCountdown(
      schedule.value?.trip?.title || 'กิจกรรม',
      route.params.scheduleId,
      preselectedRegion,
      passengerCount.value
    );
  }
  step.value = 1;
}

async function lockAndNext() {
  lockingSeats.value = true;
  seatError.value = '';
  try {
    await seatsStore.lockSeats(route.params.scheduleId, seatsStore.selectedSeatIds);
    seatsStore.setActiveBookingInfo({
      tripTitle: schedule.value?.trip?.title || 'กิจกรรม',
      scheduleId: route.params.scheduleId,
      region: preselectedRegion,
      step: isTrekking.value ? 2 : 1,
      passengerCount: seatsStore.selectedSeats.length,
      startedAt: Date.now(),
    });
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

function scrollToPassenger(index) {
  nextTick(() => {
    const card = document.getElementById(`passenger-card-${index}`);
    const target = card?.querySelector('.field-error') || card;
    target?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });
}

function goToSummary() {
  if (!isPassengerValid.value) {
    attempted.value = true;
    const idx = firstInvalidPassenger.value;
    const firstMsg = Object.values(passengerErrors.value[idx] || {})[0];
    toast.error(`ผู้เดินทางคนที่ ${idx + 1}: ${firstMsg || 'กรุณากรอกข้อมูลให้ครบถ้วน'}`);
    scrollToPassenger(idx);
    return;
  }
  step.value = isTrekking.value ? 3 : 2;
}

async function createBooking() {
  const { isConfirmed } = await Swal.fire({
    title: 'เงื่อนไขก่อนยืนยันการจอง',
    html: `
      <div style="text-align:left; font-size:14px; color:#374151; line-height:1.7;">
        <p style="font-weight:700; color:#0f766e; margin-bottom:10px; font-size:15px;">การสำรองที่นั่ง และการเปลี่ยนแปลง</p>
        <ol style="padding-left:18px; margin:0 0 16px 0; display:flex; flex-direction:column; gap:8px;">
          <li>1.เมื่อท่านยืนยันสิทธิ์การเดินทางแล้ว ทางทีมงานขอสงวนสิทธิ์ในการคืนเงินมัดจำ / ค่าทริป<strong>ทุกกรณี</strong></li>
          <li>2.หากไม่สะดวกในวันดังกล่าว สามารถแจ้งเลื่อนได้ <strong>1 ครั้ง</strong> โดยรบกวนแจ้งล่วงหน้าอย่างน้อย <strong>30 วัน</strong> ก่อนวันเดินทางเดิม</li>
          <li>3.กรณีต้องการเปลี่ยนแปลงตัวผู้เดินทาง สามารถหาคนมาแทนได้ โดยรบกวนแจ้งรายละเอียดให้ทีมงานทราบล่วงหน้าอย่างน้อย <strong>15 วัน</strong></li>
        </ol>
        <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:10px 14px; margin-bottom:14px; font-size:13px; color:#166534;">
          <strong>สรุปการจอง:</strong> จำนวน ${passengers.value.length} ท่าน · ฿${totalAmount.value.toLocaleString()}
        </div>
        <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; user-select:none;">
          <input type="checkbox" id="swal-terms-checkbox" style="margin-top:3px; width:16px; height:16px; accent-color:#0f766e; cursor:pointer; flex-shrink:0;" />
          <span>ข้าพเจ้าได้อ่านและ<strong>ยอมรับเงื่อนไข</strong>ข้างต้นทุกข้อแล้ว</span>
        </label>
      </div>
    `,
    icon: 'info',
    showCancelButton: true,
    confirmButtonText: 'ยืนยันและชำระเงิน',

    cancelButtonText: 'ยกเลิก',
    customClass: {
      popup: 'swal-popup',
      confirmButton: 'swal-btn-confirm',
      cancelButton: 'swal-btn-cancel',
      title: 'swal-title',
      htmlContainer: 'swal-html',
    },
    buttonsStyling: false,
    reverseButtons: true,
    preConfirm: () => {
      const cb = document.getElementById('swal-terms-checkbox');
      if (!cb || !cb.checked) {
        Swal.showValidationMessage('กรุณายอมรับเงื่อนไขก่อนดำเนินการต่อ');
        return false;
      }
      return true;
    },
  });
  if (!isConfirmed) return;

  bookingLoading.value = true;
  bookingError.value = '';
  try {
    // ปักหมุดจุดรับเอง = ไม่ได้เลือกจุดที่กำหนด แล้วมีหมุด
    const usingCustomPickup = !selectedPickup.value && !!customPickup.value;
    const data = {
      schedule_id: parseInt(route.params.scheduleId),
      // เมื่อปักหมุดเอง ห้ามส่ง region/จุดรับตายตัว ไม่งั้น backend จะจับคู่จุดรับตายตัว
      // แล้วมองข้ามหมุด (useCustomPickup = false) ทำให้ข้อมูลหมุดไม่ถูกบันทึก
      pickup_region: usingCustomPickup ? null : (selectedPickup.value?.region || preselectedRegion || null),
      pickup_point_id: usingCustomPickup ? null : (selectedPickup.value?.id || null),
      is_group: isGroup.value,
      group_name: isGroup.value ? groupName.value : null,
      group_notes: isGroup.value ? groupNotes.value : null,
      booking_for: bookingFor.value,
      passengers: passengers.value.map(p => ({
        title: p.title || null,
        name: String(p.name || '').trim(),
        nickname: String(p.nickname || '').trim(),
        id_card: digitsOnly(p.id_card),
        birth_date: p.birth_date || null,
        phone: digitsOnly(p.phone),
        email: p.email ? String(p.email).trim() : null,
        blood_group: p.blood_group || null,
        allergies: String(p.allergies || '').trim(),
        halal_food: p.halal_food,
        health_notes: String(p.health_notes || '').trim(),
        emergency_contact: String(p.emergency_contact || '').trim(),
        emergency_phone: digitsOnly(p.emergency_phone),
        dive_cert_level: p.dive_cert_level || null,
        cert_number: p.cert_number || null,
        weight: p.weight || null,
        // ปักหมุดเองเป็นระดับการจอง — ไม่ผูกจุดรับรายคน ไม่งั้นหน้าสตาฟจะจัดกลุ่มเข้าจุดตายตัว
        pickup_point_id: usingCustomPickup ? null : (p.pickup_point_id || null),
      })),
    };
    // จุดรับที่ลูกค้าปักหมุดเอง (ส่งเมื่อไม่ได้เลือกจุดที่กำหนด) — รอแอดมินยืนยันราคา
    if (usingCustomPickup) {
      data.custom_pickup_label = customPickup.value.label;
      data.custom_pickup_lat = customPickup.value.lat;
      data.custom_pickup_lng = customPickup.value.lng;
      data.custom_pickup_note = customPickup.value.note;
    }
    if (promotionCode.value) {
      data.promotion_code = promotionCode.value;
    }
    if (isJoinTrip.value) {
      data.is_join_trip = true;
    }
    if (selectedAddons.value.length) {
      data.selected_addons = selectedAddons.value;
    }
    if (hasSeatMap.value) data.seat_ids = seatsStore.selectedSeatIds;

    const res = await bookingStore.createBooking(data);
    seatsStore.clearSelection();
    clearFormData();
    toast.success('สร้างการจองสำเร็จ! กำลังไปยังหน้าชำระเงิน...');
    router.push(`/payment/${res.data.booking_ref}`);
  } catch (e) {
    bookingError.value = e?.response?.data?.message || 'ไม่สามารถสร้างการจองได้';
    toast.error(bookingError.value);
  } finally {
    bookingLoading.value = false;
  }
}

function handleExpiry() {
  // Read the minutes BEFORE clearing — clearSelection wipes activeBookingInfo
  const minutes = seatsStore.bookingTotalMinutes;
  seatsStore.clearSelection();
  clearFormData();

  swal.error(
    'หมดเวลาการจองแล้ว!',
    `เวลา ${minutes} นาทีสำหรับการจองหมดลงแล้ว ที่นั่งที่ล็อคไว้ถูกปลดล็อคแล้ว กรุณาเริ่มต้นการจองใหม่`
  ).then(() => {
    router.push('/trips');
  });
}

onMounted(async () => {
  seatsStore.onExpire(handleExpiry);
  try {
    const res = await api.get(`/schedules/${route.params.scheduleId}`);
    schedule.value = res.data.data;
    await seatsStore.fetchSeatMap(route.params.scheduleId);
    
    if (route.query.join_trip == 1 && schedule.value?.join_trip_enabled) {
      isJoinTrip.value = true;
    }

    if (preselectedRegion && pickupPoints.value.length > 0) {
      if (pickupPoints.value.length === 1 || (!isTrekking.value && !selectedPickup.value)) {
        selectedPickup.value = pickupPoints.value[0];
      }
    }

    // ── Session isolation: clear stale session from a different schedule/region ──
    const wasMismatch = seatsStore.clearIfMismatch(route.params.scheduleId, preselectedRegion);
    if (wasMismatch) {
      // Also clear the form data for this specific page (prevents stale passenger data)
      clearFormData();
    }

    // Resume countdown first (may have been restored from sessionStorage)
    seatsStore.restoreCountdown();

    // Restore step from session if returning mid-booking
    const hasValidSession = seatsStore.lockExpiry && new Date(seatsStore.lockExpiry) > new Date();
    const isSameSchedule = seatsStore.activeBookingInfo?.scheduleId == route.params.scheduleId;
    const isSameRegion = (seatsStore.activeBookingInfo?.region || null) === (preselectedRegion || null);
    const savedStep = seatsStore.activeBookingInfo?.step;

    if (hasValidSession && isSameSchedule && isSameRegion) {
      restoreFormData();
      if (savedStep != null && savedStep > 0) {
        step.value = savedStep;
      }
      if (hasSeatMap.value && seatsStore.selectedSeats.length > 0) {
        passengerCount.value = seatsStore.selectedSeats.length;
      }
    } else if (!hasSeatMap.value && !isTrekking.value) {
      // For non-seat-map, non-trekking trips (or Join Trip): start countdown immediately on page load
      seatsStore.startManualCountdown(
        schedule.value?.trip?.title || 'กิจกรรม',
        route.params.scheduleId,
        isJoinTrip.value ? null : preselectedRegion,
        passengerCount.value
      );
      if (isJoinTrip.value) {
        step.value = 0; // Info Step
      }
    }
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
  }
});

onBeforeUnmount(() => {
  seatsStore.offExpire(handleExpiry);
  // Do NOT clearSelection here — keep the countdown alive so the global banner
  // continues to show on other pages. It will be cleared on expiry or booking success.
});
</script>
