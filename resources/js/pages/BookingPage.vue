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
            <p class="text-sm text-gray-400 mt-1">เจ้าหน้าที่จะติดต่อท่านเพื่อยืนยันจุดรับอีกครั้ง</p>
            <button @click="skipRegionStep" class="mt-6 px-6 py-2 rounded-xl bg-teal-50 text-teal-700 font-bold hover:bg-teal-100 transition-all">
              ดำเนินการต่อ
            </button>
          </div>

          <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div
              v-for="pt in pickupPoints" :key="pt.id"
              @click="selectRegion(pt)"
              class="group cursor-pointer p-6 rounded-[2rem] border-2 transition-all duration-300 relative overflow-hidden flex flex-col justify-between h-full"
              :class="selectedPickup?.id === pt.id
                ? 'border-emerald-500 bg-emerald-50/30 ring-4 ring-emerald-500/10'
                : 'border-gray-100 bg-white hover:border-teal-500/30 hover:shadow-xl hover:shadow-teal-900/5 hover:-translate-y-1'">
              
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

          <div class="mt-10 flex flex-col sm:flex-row gap-4 justify-between items-center bg-white p-6 rounded-[2rem] shadow-sm border border-gray-100">
            <button @click="$router.push('/trips')"
              class="w-full sm:w-auto flex items-center justify-center gap-2 text-gray-500 hover:text-gray-900 px-6 py-3 rounded-2xl font-bold transition-all">
              <span class="material-symbols-rounded text-[20px]">arrow_back</span>
              ยกเลิก
            </button>
            
            <div class="flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
              <button
                @click="confirmRegion"
                :disabled="!selectedPickup && pickupPoints.length > 0"
                class="w-full sm:w-auto bg-teal-600 text-white px-10 py-4 rounded-2xl font-bold text-lg hover:bg-teal-700 active:scale-95 transition-all duration-300 shadow-xl shadow-teal-600/20 disabled:opacity-50 disabled:grayscale disabled:cursor-not-allowed group flex items-center justify-center gap-3">
                <span>{{ pickupPoints.length ? 'ไปเลือกที่นั่ง' : 'ขั้นตอนถัดไป' }}</span>
                <span class="material-symbols-rounded transition-transform group-hover:translate-x-1">arrow_forward</span>
              </button>
            </div>
          </div>
          <p v-if="!selectedPickup && pickupPoints.length > 0" class="text-center mt-4 text-sm text-red-500 font-bold animate-pulse">
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

            <!-- Vehicle Info (below seat map) -->
            <div v-if="schedule.vehicle" class="mt-8 bg-white rounded-3xl overflow-hidden shadow-sm border border-gray-100">

              <!-- Image Carousel -->
              <div v-if="schedule.vehicle.images && schedule.vehicle.images.length" class="relative group touch-pan-y overflow-hidden rounded-3xl"
                @touchstart="vehicleTouchStart"
                @touchend="vehicleTouchEnd">
                <div class="overflow-hidden relative bg-gray-200 z-0" style="aspect-ratio:16/9; touch-action: pan-y;">
                  <!-- Preload & Render Images -->
                  <img
                    v-for="(img, i) in schedule.vehicle.images"
                    :key="i"
                    :src="img"
                    :alt="schedule.vehicle.name"
                    class="absolute inset-0 w-full h-full object-cover transition-all duration-500 transform-gpu"
                    :class="[
                      vehicleImageIndex === i ? 'opacity-100 scale-100 z-10' : 'opacity-0 scale-110 z-0 pointer-events-none'
                    ]"
                    :loading="i === 0 ? 'eager' : 'lazy'"
                    style="will-change: opacity, transform;"
                  />
                </div>

                <!-- Navigation Buttons -->
                <button v-if="schedule.vehicle.images.length > 1"
                  @click.stop="vehicleImageIndex = (vehicleImageIndex - 1 + schedule.vehicle.images.length) % schedule.vehicle.images.length"
                  class="absolute left-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-black/40 hover:bg-black/60 text-white backdrop-blur-md flex items-center justify-center transition-all z-20 opacity-100 md:opacity-0 md:group-hover:opacity-100 md:-translate-x-2 md:group-hover:translate-x-0 active:scale-90">
                  <span class="material-symbols-rounded text-[24px]">chevron_left</span>
                </button>
                <button v-if="schedule.vehicle.images.length > 1"
                  @click.stop="vehicleImageIndex = (vehicleImageIndex + 1) % schedule.vehicle.images.length"
                  class="absolute right-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-black/40 hover:bg-black/60 text-white backdrop-blur-md flex items-center justify-center transition-all z-20 opacity-100 md:opacity-0 md:group-hover:opacity-100 md:translate-x-2 md:group-hover:translate-x-0 active:scale-90">
                  <span class="material-symbols-rounded text-[24px]">chevron_right</span>
                </button>

                <!-- Indicators -->
                <div v-if="schedule.vehicle.images.length > 1" class="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-2 bg-black/20 backdrop-blur-md px-3 py-2 rounded-full z-20">
                  <button v-for="(_, i) in schedule.vehicle.images" :key="i"
                    @click.stop="vehicleImageIndex = i"
                    class="rounded-full transition-all touch-manipulation"
                    :class="vehicleImageIndex === i ? 'bg-white w-5 h-1.5' : 'bg-white/40 hover:bg-white/70 w-1.5 h-1.5'">
                  </button>
                </div>

                <!-- Counter -->
                <div class="absolute top-4 right-4 px-3 py-1.5 rounded-xl bg-black/40 backdrop-blur-md text-white text-[10px] font-black shadow-lg z-20 tracking-wider">
                  {{ vehicleImageIndex + 1 }} / {{ schedule.vehicle.images.length }}
                </div>
              </div>

              <!-- Interior video -->
              <div v-if="schedule.vehicle.interior_video" class="px-5 pt-5">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-[16px]">videocam</span>วิดีโอภายในรถ
                </p>
                <video :src="schedule.vehicle.interior_video" controls class="w-full rounded-2xl border border-gray-100"></video>
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
                  <div v-if="schedule.vehicle.driver_name" class="mt-3 flex items-center gap-3">
                    <img v-if="schedule.vehicle.driver_photo" :src="schedule.vehicle.driver_photo"
                      class="w-9 h-9 rounded-full object-cover border-2 border-gray-200 shrink-0" />
                    <span v-else class="w-9 h-9 rounded-full bg-gray-100 border border-gray-200 flex items-center justify-center shrink-0">
                      <span class="material-symbols-rounded text-[18px] text-gray-400">person</span>
                    </span>
                    <div class="min-w-0">
                      <p class="text-xs text-gray-500 font-medium">คนขับ</p>
                      <p class="text-sm font-bold text-gray-900 truncate">{{ schedule.vehicle.driver_name }}</p>
                      <p v-if="schedule.vehicle.driver_phone" class="text-xs text-teal-600 font-medium">{{ schedule.vehicle.driver_phone }}</p>
                    </div>
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

            <!-- Passenger forms -->
            <div v-for="(p, i) in passengers" :key="i"
              class="bg-white border border-gray-100 rounded-3xl p-6 md:p-8 mb-6 shadow-sm relative overflow-hidden transition-all hover:shadow-md hover:border-gray-200">
              
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
                  <label class="block text-sm font-bold text-gray-700 mb-2">ชื่อเล่น <span class="text-red-500">*</span></label>
                  <input v-model="p.nickname" type="text" required placeholder="กรอกชื่อเล่น"
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
                    inputmode="numeric" pattern="[0-9]{13}"
                    maxlength="13"
                    @input="limitDigits(p, 'id_card', 13)"
                    class="w-full border-2 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                    :class="p.id_card && !hasExactDigits(p.id_card, 13) ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'" />
                  <p v-if="p.id_card && !hasExactDigits(p.id_card, 13)" class="text-xs text-red-500 font-bold mt-2">กรุณากรอกเลขบัตรประชาชน 13 หลัก</p>
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">เบอร์โทรศัพท์ <span class="text-red-500">*</span></label>
                  <input v-model="p.phone" type="tel" required placeholder="0XXXXXXXXX"
                    inputmode="numeric" pattern="[0-9]{10}" maxlength="10"
                    @input="limitDigits(p, 'phone', 10)"
                    class="w-full border-2 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                    :class="p.phone && !hasExactDigits(p.phone, 10) ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'" />
                  <p v-if="p.phone && !hasExactDigits(p.phone, 10)" class="text-xs text-red-500 font-bold mt-2">กรุณากรอกเบอร์โทรศัพท์ 10 หลัก</p>
                </div>
                <div v-if="bookingFor === 'friend' && i === 0">
                  <label class="block text-sm font-bold text-gray-700 mb-2">อีเมลสำหรับแจ้งสถานะการจอง <span class="text-red-500">*</span></label>
                  <input v-model.trim="p.email" type="email" required placeholder="friend@example.com"
                    class="w-full border-2 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                    :class="p.email && !isValidEmail(p.email) ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'" />
                  <p v-if="p.email && !isValidEmail(p.email)" class="text-xs text-red-500 font-bold mt-2">รูปแบบอีเมลไม่ถูกต้อง</p>
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">กรุ๊ปเลือด <span class="text-red-500">*</span></label>
                  <select v-model="p.blood_group" required
                    class="w-full border-2 border-gray-200 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all bg-gray-50/50 hover:bg-gray-50 focus:bg-white">
                    <option value="" disabled>เลือกกรุ๊ปเลือด</option>
                    <option value="A">A</option>
                    <option value="B">B</option>
                    <option value="O">O</option>
                    <option value="AB">AB</option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">ผู้ติดต่อฉุกเฉิน <span class="text-red-500">*</span></label>
                  <input v-model="p.emergency_contact" type="text" required placeholder="ชื่อผู้ติดต่อ"
                    class="w-full border-2 border-gray-200 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white" />
                </div>
                <div>
                  <label class="block text-sm font-bold text-gray-700 mb-2">เบอร์ฉุกเฉิน <span class="text-red-500">*</span></label>
                  <input v-model="p.emergency_phone" type="tel" required placeholder="0XXXXXXXXX"
                    inputmode="numeric" pattern="[0-9]{10}" maxlength="10"
                    @input="limitDigits(p, 'emergency_phone', 10)"
                    class="w-full border-2 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white"
                    :class="p.emergency_phone && !hasExactDigits(p.emergency_phone, 10) ? 'border-red-300 focus:border-red-500 focus:ring-red-500/10' : 'border-gray-200'" />
                  <p v-if="p.emergency_phone && !hasExactDigits(p.emergency_phone, 10)" class="text-xs text-red-500 font-bold mt-2">กรุณากรอกเบอร์ฉุกเฉิน 10 หลัก</p>
                </div>
                <div class="md:col-span-2">
                  <label class="block text-sm font-bold text-gray-700 mb-2">ต้องการอาหารฮาลาล <span class="text-red-500">*</span></label>
                  <p class="text-xs text-gray-500 mb-3">เพื่อให้เราจัดเตรียมอาหารได้เหมาะสม รบกวนแจ้งว่าท่านต้องการอาหารฮาลาลหรือไม่</p>
                  <div class="flex gap-3">
                    <label class="flex-1 flex items-center gap-3 border-2 rounded-2xl px-4 py-3 cursor-pointer transition-all"
                      :class="p.halal_food === true ? 'border-teal-600 bg-teal-50' : 'border-gray-200 bg-gray-50/50 hover:bg-gray-50'">
                      <input type="radio" :name="`halal_food_${i}`" :value="true" v-model="p.halal_food" required class="hidden" />
                      <span class="w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 transition-all"
                        :class="p.halal_food === true ? 'border-teal-600' : 'border-gray-300'">
                        <span v-if="p.halal_food === true" class="w-2.5 h-2.5 rounded-full bg-teal-600"></span>
                      </span>
                      <span class="text-sm font-bold" :class="p.halal_food === true ? 'text-teal-700' : 'text-gray-700'">ต้องการ</span>
                    </label>
                    <label class="flex-1 flex items-center gap-3 border-2 rounded-2xl px-4 py-3 cursor-pointer transition-all"
                      :class="p.halal_food === false ? 'border-gray-400 bg-gray-100' : 'border-gray-200 bg-gray-50/50 hover:bg-gray-50'">
                      <input type="radio" :name="`halal_food_${i}`" :value="false" v-model="p.halal_food" required class="hidden" />
                      <span class="w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 transition-all"
                        :class="p.halal_food === false ? 'border-gray-500' : 'border-gray-300'">
                        <span v-if="p.halal_food === false" class="w-2.5 h-2.5 rounded-full bg-gray-500"></span>
                      </span>
                      <span class="text-sm font-bold" :class="p.halal_food === false ? 'text-gray-700' : 'text-gray-700'">ไม่จำเป็น</span>
                    </label>
                  </div>
                </div>

                <div class="md:col-span-2">
                  <label class="block text-sm font-bold text-gray-700 mb-2">การแพ้อาหาร / อื่นๆ <span class="text-red-500">*</span></label>
                  <input v-model="p.allergies" type="text" required placeholder="เช่น แพ้อาหารทะเล, ไม่ทานเนื้อ หรือ ไม่มี"
                    class="w-full border-2 border-gray-200 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white" />
                </div>

                <div class="md:col-span-2">
                  <label class="block text-sm font-bold text-gray-700 mb-2">หมายเหตุสุขภาพ <span class="text-red-500">*</span></label>
                  <textarea v-model="p.health_notes" rows="2" required placeholder="แพ้ยา, โรคประจำตัว หรือ ไม่มี"
                    class="w-full border-2 border-gray-200 rounded-2xl px-4 py-3.5 text-sm text-gray-900 focus:ring-4 focus:ring-teal-600/10 focus:border-teal-600 outline-none transition-all placeholder:text-gray-400 bg-gray-50/50 hover:bg-gray-50 focus:bg-white resize-none"></textarea>
                </div>
              </div>
            </div>

            <div class="mt-8 flex flex-col sm:flex-row gap-4 justify-end">
              <button @click="step = hasSeatMap ? (isTrekking ? 1 : 0) : (isTrekking ? 0 : -1); if (step === -1) $router.push('/trips')"
                class="w-full sm:w-auto flex items-center justify-center gap-2 bg-white border-2 border-gray-200 text-gray-700 px-8 py-4 rounded-2xl font-bold text-base hover:bg-gray-50 hover:border-gray-300 active:scale-95 transition-all duration-300">
                <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 0,'wght' 400">arrow_back</span>
                ย้อนกลับ
              </button>
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
                    <div class="flex justify-between text-sm">
                      <span class="text-white/70">ราคาต่อที่นั่ง</span>
                      <span class="font-bold">฿{{ Number(effectivePrice).toLocaleString() }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                      <span class="text-white/70">จำนวนผู้เดินทาง</span>
                      <span class="font-bold">{{ passengers.length }} คน</span>
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
                :disabled="!isPassengerValid"
                class="w-full bg-teal-600 text-white py-4 rounded-2xl font-black text-lg hover:bg-teal-700 active:scale-[0.98] transition-all duration-300 shadow-xl shadow-teal-600/30 disabled:opacity-50 disabled:grayscale disabled:cursor-not-allowed flex items-center justify-center gap-3">
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
          :disabled="!isPassengerValid"
          class="flex-1 bg-teal-600 text-white py-3.5 rounded-2xl font-bold text-sm hover:bg-teal-700 active:scale-95 transition-all shadow-lg shadow-teal-600/20 disabled:opacity-50 disabled:grayscale flex items-center justify-center gap-2">
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
          :disabled="!selectedPickup && pickupPoints.length > 0"
          class="flex-1 bg-teal-600 text-white py-3.5 rounded-2xl font-bold text-sm hover:bg-teal-700 active:scale-95 transition-all shadow-lg shadow-teal-600/20 disabled:opacity-50 disabled:grayscale flex items-center justify-center gap-2">
          <span>ไปเลือกที่นั่ง</span>
          <span class="material-symbols-rounded text-lg">arrow_forward</span>
        </button>
      </div>
    </div>
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
  const all = schedule.value?.pickup_points || [];
  if (!preselectedRegion) return all;
  const filtered = all.filter(pt => pt.region === preselectedRegion);
  return filtered.length ? filtered : all;
});
const selectedPickup = ref(null);

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

const passengers = ref([{ 
  title: '', name: '', nickname: '', id_card: '', phone: '', email: '', blood_group: '', allergies: '',
  health_notes: '', emergency_contact: '', emergency_phone: '', 
  dive_cert_level: '', cert_number: '', weight: null, halal_food: null
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
      passengerCount.value = data.passengerCount ?? data.passengers.length;
    }
    bookingFor.value = data.bookingFor || 'self';
    isGroup.value = data.isGroup ?? false;
    groupName.value = data.groupName ?? '';
    groupNotes.value = data.groupNotes ?? '';
    promotionCode.value = data.promotionCode ?? '';
    promotionInput.value = data.promotionCode ?? '';
    promotionData.value = data.promotionData ?? null;
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
watch([bookingFor, isGroup, groupName, groupNotes, passengerCount, selectedPickup, promotionCode, promotionData], saveFormData);

watch(passengerCount, (n) => {
  seatsStore.updateBookingDuration(n);
  while (passengers.value.length < n) {
    passengers.value.push({ 
      title: '', name: '', nickname: '', id_card: '', phone: '', email: '', blood_group: '', allergies: '',
      health_notes: '', emergency_contact: '', emergency_phone: '', 
      dive_cert_level: '', cert_number: '', weight: null, halal_food: null
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
    email: user.email || '',
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

const isValidEmail = (email) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(email || '').trim());
const isFriendEmailValid = computed(() => bookingFor.value !== 'friend' || isValidEmail(passengers.value[0]?.email));
const digitsOnly = (value) => String(value || '').replace(/\D/g, '');
const hasText = (value) => String(value || '').trim().length > 0;
const hasExactDigits = (value, length) => digitsOnly(value).length === length;

function limitDigits(passenger, field, length) {
  passenger[field] = digitsOnly(passenger[field]).slice(0, length);
}

const isPassengerValid = computed(() => isFriendEmailValid.value && passengers.value.every(p => 
  p.title &&
  hasText(p.name) &&
  hasText(p.nickname) &&
  hasExactDigits(p.id_card, 13) &&
  hasExactDigits(p.phone, 10) &&
  p.blood_group &&
  p.halal_food !== null &&
  hasText(p.emergency_contact) &&
  hasExactDigits(p.emergency_phone, 10) &&
  hasText(p.allergies) &&
  hasText(p.health_notes) &&
  (!schedule.value?.trip?.is_women_only || ['นาง', 'นางสาว'].includes(p.title))
));
const seatCount = computed(() => hasSeatMap.value ? seatsStore.selectedSeats.length || 1 : passengers.value.length);

const subtotalAmount = computed(() => effectivePrice.value * seatCount.value);

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
}

function confirmRegion() {
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

function goToSummary() {
  if (!isPassengerValid.value) return;
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
    const data = {
      schedule_id: parseInt(route.params.scheduleId),
      pickup_region: selectedPickup.value?.region || preselectedRegion || null,
      pickup_point_id: selectedPickup.value?.id || null,
      is_group: isGroup.value,
      group_name: isGroup.value ? groupName.value : null,
      group_notes: isGroup.value ? groupNotes.value : null,
      booking_for: bookingFor.value,
      passengers: passengers.value.map(p => ({
        title: p.title || null,
        name: String(p.name || '').trim(),
        nickname: String(p.nickname || '').trim(),
        id_card: digitsOnly(p.id_card),
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
      })),
    };
    if (promotionCode.value) {
      data.promotion_code = promotionCode.value;
    }
    if (isJoinTrip.value) {
      data.is_join_trip = true;
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
  seatsStore.clearSelection();
  clearFormData();
  const minutes = seatsStore.activeBookingInfo?.passengerCount 
    ? Math.floor((10 * 60 + (seatsStore.activeBookingInfo.passengerCount - 1) * 2 * 60) / 60)
    : 10;
    
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
