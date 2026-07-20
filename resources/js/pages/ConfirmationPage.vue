<template>
  <div class="min-h-screen bg-gray-50/30 flex flex-col pt-8 pb-24 relative overflow-hidden font-anuphan">
    
    <!-- Background Decor -->
    <div class="absolute top-0 left-1/2 -translate-x-1/2 w-full h-[500px] bg-gradient-to-b from-teal-50/50 to-transparent -z-0"></div>
    <div class="absolute -top-24 -right-24 w-96 h-96 bg-teal-100/20 rounded-full blur-3xl -z-0"></div>
    <div class="absolute top-1/2 -left-24 w-72 h-72 bg-amber-100/20 rounded-full blur-3xl -z-0"></div>

    <!-- Loading -->
    <div v-if="loading" class="grow flex items-center justify-center relative z-10">
      <div class="flex flex-col items-center gap-5">
        <div class="relative">
          <div class="w-16 h-16 rounded-full border-4 border-teal-100 border-t-teal-600 animate-spin"></div>
          <div class="absolute inset-0 flex items-center justify-center">
            <span class="material-symbols-rounded text-teal-600 text-xl animate-pulse">map</span>
          </div>
        </div>
        <p class="text-base font-black text-gray-400 tracking-wide animate-pulse uppercase">กำลังเตรียมข้อมูลการจอง...</p>
      </div>
    </div>

    <!-- Success State -->
    <main v-else-if="booking" class="grow flex items-start justify-center px-4 py-8 relative z-10">
      <div class="max-w-4xl w-full">

        <!-- Hero Header -->
        <div class="text-center mb-16 flex flex-col items-center animate-in fade-in slide-in-from-top-8 duration-1000">
          <div class="mb-8 relative">
            <!-- Glow effect for success -->
            <div v-if="booking.status === 'confirmed'" class="absolute inset-0"></div>
            
            <template v-if="booking.status === 'confirmed'">
              <img src="/images/suscess_show.webp" alt="Success" class="w-56 h-auto mx-auto object-contain drop- animate-in zoom-in fade-in duration-700 hover:scale-105 transition-transform" />
            </template>
            <template v-else-if="booking.status === 'cancelled'">
              <img src="/images/cancel_booking.webp" alt="Cancelled" class="w-56 h-auto mx-auto object-contain animate-in zoom-in fade-in duration-700" />
            </template>
            <template v-else-if="booking.status === 'pending'">
              <img src="/images/pending_show.webp" alt="Pending" class="w-56 h-auto mx-auto object-contain animate-in zoom-in fade-in duration-700" />
            </template>
            <template v-else-if="booking.status === 'refunded'">
              <img src="/images/refund_show.webp" alt="Refunded" class="w-56 h-auto mx-auto object-contain animate-in zoom-in fade-in duration-700" />
            </template>
            <div v-else class="text-gray-400">
              <span class="material-symbols-rounded text-[120px]" style="font-variation-settings:'FILL' 1,'wght' 400">info</span>
            </div>
          </div>
          
          <h1 class="text-4xl md:text-6xl font-black tracking-tight mb-4"
            :class="{
              'text-teal-900': booking.status === 'confirmed',
              'text-amber-700': booking.status === 'pending',
              'text-red-700': booking.status === 'cancelled',
              'text-blue-700': booking.status === 'refunded',
            }">
            {{ { confirmed: 'การจองเสร็จสมบูรณ์!', pending: 'รอการยืนยัน', cancelled: 'การจองถูกยกเลิก', refunded: 'คืนเงินเรียบร้อย' }[booking.status] ?? booking.status }}
          </h1>
          <p class="text-gray-500 text-lg md:text-xl max-w-xl mx-auto font-bold leading-relaxed">
            {{ {
              confirmed: 'ยินดีด้วย! เราได้รับการจองของคุณเรียบร้อยแล้ว เตรียมตัวออกไปลุยกันได้เลย',
              pending: 'เราได้รับคำขอจองของคุณแล้ว กรุณาดำเนินการชำระเงินเพื่อยืนยันสิทธิ์',
              cancelled: 'การจองนี้ถูกยกเลิกแล้ว หากคุณต้องการจองใหม่หรือมีข้อสงสัย โปรดติดต่อเรา',
              refunded: 'ดำเนินการคืนเงินเสร็จสิ้น ยอดเงินจะกลับเข้าบัญชีของคุณภายในระยะเวลาที่กำหนด',
            }[booking.status] ?? '' }}
          </p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
          <!-- Left Column: Summary & Travelers -->
          <div class="lg:col-span-7 space-y-8 animate-in fade-in slide-in-from-left-8 duration-700 delay-300 fill-mode-both">
            
            <!-- Booking Summary Card -->
            <section class="bg-white rounded-[2.5rem] border border-gray-100 overflow-hidden transition-all">
              <div class="flex flex-col">
                <!-- Trip Image Banner -->
                <div class="w-full h-48 md:h-56 overflow-hidden relative group">
                  <img
                    v-if="booking.schedule?.trip?.thumbnail_image || booking.schedule?.trip?.cover_image"
                    :src="booking.schedule.trip.thumbnail_image || booking.schedule.trip.cover_image"
                    :alt="booking.schedule.trip.title"
                    class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
                  />
                  <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent"></div>
                  <div class="absolute bottom-4 left-6">
                    <span class="text-[10px] font-black tracking-widest text-white bg-teal-600/80 backdrop-blur-md px-3 py-1.5 rounded-full mb-2 inline-block uppercase border border-white/20">
                      สรุปการจอง
                    </span>
                    <h2 class="text-xl md:text-2xl font-black text-white leading-tight">
                      {{ booking.schedule?.trip?.title }}
                    </h2>
                  </div>
                </div>

                <div class="p-6 md:p-10 space-y-8">
                  <!-- Meta Header: Ref & Status -->
                  <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="bg-gray-50 px-5 py-3 rounded-2xl border border-gray-100 flex flex-col">
                      <span class="text-[10px] text-gray-400 font-black uppercase tracking-widest mb-1">รหัสการจอง</span>
                      <span class="font-mono font-black text-teal-800 text-lg leading-none tracking-tight">{{ booking.booking_ref }}</span>
                    </div>
                    <div class="flex items-center gap-2 font-black px-4 py-2.5 rounded-xl border-2"
                      :class="{
                        'bg-teal-50 text-teal-700 border-teal-100': booking.status === 'confirmed',
                        'bg-amber-50 text-amber-600 border-amber-100': booking.status === 'pending',
                        'bg-red-50 text-red-600 border-red-100': booking.status === 'cancelled',
                        'bg-blue-50 text-blue-600 border-blue-100': booking.status === 'refunded',
                      }">
                      <span class="material-symbols-rounded text-[20px]" style="font-variation-settings:'FILL' 1">
                        {{ { confirmed: 'verified', pending: 'schedule', cancelled: 'cancel', refunded: 'currency_exchange' }[booking.status] ?? 'info' }}
                      </span>
                      <span class="text-sm uppercase tracking-wide">{{ statusLabel }}</span>
                    </div>
                  </div>

                  <!-- Details Grid -->
                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-6 gap-x-8">
                    <div v-if="booking.passengers?.length" class="sm:col-span-2 rounded-3xl bg-gray-50 border border-gray-100 p-5">
                      <div class="flex items-center gap-3 mb-4">
                        <div class="w-10 h-10 rounded-2xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100">
                          <span class="material-symbols-rounded text-teal-600 text-[22px]">badge</span>
                        </div>
                        <div>
                          <p class="text-[11px] font-black text-gray-400 uppercase tracking-widest mb-0.5">รายชื่อผู้ร่วมทริป</p>
                          <p class="text-sm font-bold text-gray-500">คำนำหน้า / ชื่อ / นามสกุล</p>
                        </div>
                      </div>
                      <div class="grid grid-cols-1 gap-3">
                        <div
                          v-for="(p, i) in booking.passengers"
                          :key="p.id || i"
                          class="bg-white rounded-2xl border border-gray-100 px-4 py-3"
                        >
                          <div class="grid grid-cols-[auto_1fr] sm:grid-cols-[auto_0.8fr_1fr_1fr] gap-3 items-center">
                            <span class="w-7 h-7 rounded-full bg-teal-600 text-white text-xs font-black flex items-center justify-center">{{ i + 1 }}</span>
                            <div>
                              <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">คำนำหน้า</p>
                              <p class="font-black text-gray-900">{{ p.title || '-' }}</p>
                            </div>
                            <div>
                              <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">ชื่อ</p>
                              <p class="font-black text-gray-900">{{ passengerNameParts(p).firstName }}</p>
                            </div>
                            <div class="col-span-2 sm:col-span-1">
                              <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">นามสกุล</p>
                              <p class="font-black text-gray-900">{{ passengerNameParts(p).lastName }}</p>
                            </div>
                          </div>
                          <div v-if="passengerPickupLabel(p)" class="mt-2 pt-2 border-t border-gray-50 flex items-center gap-1.5">
                            <span class="material-symbols-rounded text-teal-500 text-[14px]">location_on</span>
                            <p class="text-xs font-bold text-teal-700">{{ passengerPickupLabel(p) }}</p>
                          </div>
                        </div>
                      </div>
                    </div>

                    <div class="flex items-center gap-4 group">
                      <div class="w-12 h-12 rounded-2xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100 transition-colors group-hover:bg-teal-100 group-hover:scale-110 duration-300">
                        <span class="material-symbols-rounded text-teal-600 text-[24px]">calendar_today</span>
                      </div>
                      <div>
                        <p class="text-[11px] font-black text-gray-400 uppercase tracking-widest mb-0.5">วันที่เดินทาง</p>
                        <p class="font-black text-base text-gray-900 leading-tight">{{ formatDate(booking.schedule?.departure_date) }}</p>
                      </div>
                    </div>

                    <div class="flex items-center gap-4 group">
                      <div class="w-12 h-12 rounded-2xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100 transition-colors group-hover:bg-teal-100 group-hover:scale-110 duration-300">
                        <span class="material-symbols-rounded text-teal-600 text-[24px]">payments</span>
                      </div>
                      <div>
                        <p class="text-[11px] font-black text-gray-400 uppercase tracking-widest mb-0.5">ยอดรวมทั้งหมด</p>
                        <p class="font-black text-xl text-teal-700 leading-tight">฿{{ Number(booking.total_amount).toLocaleString() }}</p>
                      </div>
                    </div>

                    <div v-if="booking.pickup_point || booking.pickup_region || booking.custom_pickup" class="flex items-center gap-4 group sm:col-span-2">
                      <div class="w-12 h-12 rounded-2xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100 transition-colors group-hover:bg-teal-100 group-hover:scale-110 duration-300">
                        <span class="material-symbols-rounded text-teal-600 text-[24px]">{{ booking.custom_pickup && !booking.pickup_point && !booking.pickup_region ? 'add_location_alt' : 'location_on' }}</span>
                      </div>
                      <div class="min-w-0">
                        <p class="text-[11px] font-black text-gray-400 uppercase tracking-widest mb-0.5">จุดขึ้นรถ / จุดนัดพบ</p>
                        <p v-if="!hasVariedPassengerPickups" class="font-black text-base text-gray-900 truncate">{{ pickupLabel }}</p>
                        <p v-else class="font-black text-base text-gray-900">หลายจุด (ดูรายชื่อด้านบน)</p>
                        <template v-if="isCustomPickup">
                          <p v-if="booking.custom_pickup.note" class="text-sm text-gray-500 font-medium mt-0.5 truncate">{{ booking.custom_pickup.note }}</p>
                          <a :href="`https://www.google.com/maps/search/?api=1&query=${booking.custom_pickup.lat},${booking.custom_pickup.lng}`"
                            target="_blank" rel="noopener"
                            class="inline-flex items-center gap-1 text-xs font-black text-teal-600 mt-1 hover:text-teal-700">
                            <span class="material-symbols-rounded text-[16px]">map</span> ดูหมุดบนแผนที่
                          </a>
                        </template>
                      </div>
                    </div>

                    <div v-if="booking.seats?.length" class="flex items-center gap-4 group">
                      <div class="w-12 h-12 rounded-2xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100 transition-colors group-hover:bg-teal-100 group-hover:scale-110 duration-300">
                        <span class="material-symbols-rounded text-teal-600 text-[24px]">event_seat</span>
                      </div>
                      <div>
                        <p class="text-[11px] font-black text-gray-400 uppercase tracking-widest mb-0.5">ตำแหน่งที่นั่ง</p>
                        <p class="font-black text-base text-gray-900 leading-tight">{{ booking.seats.map(s => s.seat_id).join(', ') }}</p>
                      </div>
                    </div>

                    <div class="flex items-center gap-4 group">
                      <div class="w-12 h-12 rounded-2xl bg-teal-50 flex items-center justify-center shrink-0 border border-teal-100 transition-colors group-hover:bg-teal-100 group-hover:scale-110 duration-300">
                        <span class="material-symbols-rounded text-teal-600 text-[24px]">group</span>
                      </div>
                      <div>
                        <p class="text-[11px] font-black text-gray-400 uppercase tracking-widest mb-0.5">จำนวนผู้ร่วมทริป</p>
                        <p class="font-black text-base text-gray-900 leading-tight">{{ booking.passengers?.length || 0 }} ท่าน</p>
                      </div>
                    </div>

                  </div>

                  <!-- Group Info if exists -->
                  <div v-if="booking.is_group && booking.group_name" class="p-5 bg-teal-50/50 rounded-3xl border border-teal-100 flex items-start gap-4">
                    <span class="material-symbols-rounded text-teal-600 mt-1">groups</span>
                    <div>
                      <p class="text-[10px] font-black text-teal-700 uppercase tracking-widest mb-1">จองเป็นกลุ่ม: {{ booking.group_name }}</p>
                      <p v-if="booking.group_notes" class="text-sm font-bold text-teal-800 italic">"{{ booking.group_notes }}"</p>
                    </div>
                  </div>

                  <div v-if="booking.selected_addons?.length" class="p-5 bg-amber-50/70 rounded-3xl border border-amber-100">
                    <div class="flex items-center gap-2 mb-3">
                      <span class="material-symbols-rounded text-amber-600">add_task</span>
                      <p class="text-[10px] font-black text-amber-700 uppercase tracking-widest">ตัวเลือกเสริม</p>
                    </div>
                    <div class="space-y-2">
                      <div v-for="(addon, idx) in booking.selected_addons" :key="idx" class="flex justify-between gap-3 text-sm">
                        <span class="font-bold text-gray-800">{{ addon.name }}</span>
                        <span class="font-black text-amber-700">+฿{{ Number(addon.total_price || 0).toLocaleString() }}</span>
                      </div>
                    </div>
                  </div>

                  <!-- Footer: Paid Timestamp -->
                  <div v-if="booking.paid_at" class="pt-6 border-t border-gray-100 flex items-center gap-2 text-gray-400">
                    <span class="material-symbols-rounded text-base">check_circle</span>
                    <span class="text-xs font-bold uppercase tracking-wider">ชำระเงินเรียบร้อยแล้วเมื่อ {{ new Date(booking.paid_at).toLocaleString('th-TH') }}</span>
                  </div>

                  <!-- ── Installment Tracker ── -->
                  <div v-if="booking.payment_type === 'installment' && booking.installment_payments?.length" class="pt-6 border-t border-gray-100 space-y-5">
                    <div class="flex items-center justify-between">
                      <h3 class="font-black text-gray-900 flex items-center gap-2">
                        <span class="material-symbols-rounded text-amber-500 text-xl">calendar_month</span>
                        แผนผ่อนชำระ {{ booking.installment_count }} งวด
                      </h3>
                      <span class="text-[10px] font-black px-3 py-1 rounded-full"
                        :class="allInstallmentsPaid ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'">
                        {{ paidInstallmentsCount }} / {{ booking.installment_count }} งวด
                      </span>
                    </div>

                    <!-- Progress -->
                    <div class="h-2.5 bg-gray-100 rounded-full overflow-hidden">
                      <div class="h-full bg-gradient-to-r from-amber-400 to-green-500 rounded-full transition-all duration-700"
                        :style="{ width: (paidInstallmentsCount / booking.installment_count * 100) + '%' }"></div>
                    </div>

                    <!-- Steps -->
                    <div class="space-y-2">
                      <div v-for="inst in booking.installment_payments" :key="inst.installment_no"
                        class="flex items-center gap-4 p-3 rounded-xl transition-all"
                        :class="{
                          'bg-green-50 border border-green-100': inst.status === 'paid',
                          'bg-amber-50 border border-amber-200': inst.status !== 'paid' && isNextInstallment(inst),
                          'bg-gray-50 border border-gray-100': inst.status !== 'paid' && !isNextInstallment(inst),
                        }">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-black shrink-0"
                          :class="{
                            'bg-green-500 text-white': inst.status === 'paid',
                            'bg-amber-500 text-white': inst.status !== 'paid' && isNextInstallment(inst),
                            'bg-gray-200 text-gray-400': inst.status !== 'paid' && !isNextInstallment(inst),
                          }">
                          <span v-if="inst.status === 'paid'" class="material-symbols-rounded text-sm">check</span>
                          <span v-else>{{ inst.installment_no }}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                          <p class="text-sm font-black" :class="inst.status === 'paid' ? 'text-green-800' : 'text-gray-700'">
                            งวดที่ {{ inst.installment_no }} · ฿{{ Number(inst.amount).toLocaleString() }}
                          </p>
                          <p class="text-xs font-bold" :class="inst.status === 'paid' ? 'text-green-600' : 'text-gray-400'">
                            {{ inst.status === 'paid'
                              ? 'ชำระแล้ว ' + new Date(inst.paid_at).toLocaleDateString('th-TH', { day:'numeric', month:'short', year:'numeric' })
                              : 'กำหนดชำระ ' + formatDate(inst.due_date) }}
                          </p>
                        </div>
                        <span v-if="inst.status === 'paid'" class="text-xs font-black text-green-600 bg-green-100 px-2.5 py-1 rounded-lg">✓</span>
                      </div>
                    </div>

                    <!-- Pay Next Button -->
                    <router-link v-if="nextPendingInstallment" :to="`/installment-payment/${booking.booking_ref}`"
                      class="flex items-center justify-center gap-2 w-full py-3.5 rounded-xl font-black text-sm transition-all bg-amber-500 text-white hover:bg-amber-600 active:scale-[0.98]">
                      <span class="material-symbols-rounded text-lg">payments</span>
                      ชำระงวดที่ {{ nextPendingInstallment.installment_no }}
                    </router-link>

                    <!-- All Paid Badge -->
                    <div v-else class="flex items-center gap-2 p-3 bg-green-50 rounded-xl border border-green-200">
                      <span class="material-symbols-rounded text-green-600 text-lg" style="font-variation-settings:'FILL' 1">verified</span>
                      <span class="text-xs font-black text-green-700">ชำระครบทุกงวดเรียบร้อยแล้ว ✓</span>
                    </div>
                  </div>
                </div>
              </div>
            </section>

            <!-- ── Trip Team: Staff & Driver ── -->
            <section v-if="hasTeam" class="bg-white rounded-[2.5rem] border border-gray-100 overflow-hidden">
              <!-- Header Band -->
              <div class="relative bg-gradient-to-br from-teal-600 via-teal-700 to-teal-800 px-6 md:px-10 py-7 overflow-hidden">
                <div class="absolute -right-10 -top-10 w-40 h-40 bg-white/10 rounded-full blur-2xl"></div>
                <div class="absolute -left-8 -bottom-12 w-40 h-40 bg-amber-300/10 rounded-full blur-2xl"></div>
                <div class="relative z-10 flex items-center gap-4">
                  <div class="w-14 h-14 rounded-2xl bg-white/15 backdrop-blur-md flex items-center justify-center border border-white/20 shrink-0">
                    <span class="material-symbols-rounded text-white text-[30px]" style="font-variation-settings:'FILL' 1">diversity_3</span>
                  </div>
                  <div>
                    <p class="text-[10px] font-black tracking-[0.2em] text-teal-100 uppercase mb-1">ทีมงานดูแลทริปของคุณ</p>
                    <h3 class="text-xl md:text-2xl font-black text-white leading-tight">พร้อมดูแลคุณตลอดการเดินทาง</h3>
                  </div>
                </div>
              </div>

              <div class="p-6 md:p-10 space-y-6">
                <!-- Driver Card (highlighted) -->
                <div v-if="driver" class="relative rounded-3xl border border-teal-100 bg-gradient-to-br from-teal-50/70 to-white p-5 md:p-6 overflow-hidden">
                  <span class="absolute top-5 right-5 text-[10px] font-black tracking-widest text-teal-700 bg-teal-100 px-3 py-1.5 rounded-full uppercase flex items-center gap-1">
                    <span class="material-symbols-rounded text-[14px]" style="font-variation-settings:'FILL' 1">directions_bus</span>
                    คนขับรถ
                  </span>
                  <div class="flex items-start gap-4 md:gap-5">
                    <!-- Avatar -->
                    <div class="shrink-0">
                      <img v-if="driver.photo" :src="driver.photo" :alt="driver.name"
                        class="w-20 h-20 md:w-24 md:h-24 rounded-2xl object-cover border-2 border-white" />
                      <div v-else class="w-20 h-20 md:w-24 md:h-24 rounded-2xl bg-teal-600 text-white flex items-center justify-center border-2 border-white">
                        <span class="text-2xl font-black">{{ initials(driver.name) }}</span>
                      </div>
                    </div>
                    <div class="flex-1 min-w-0 pt-1">
                      <p class="text-lg md:text-xl font-black text-gray-900 leading-tight pr-16">{{ driver.name }}</p>
                      <p class="text-xs font-bold text-teal-600 mb-3">ผู้ขับขี่ประจำรถของคุณ</p>

                      <!-- Vehicle chips -->
                      <div v-if="driver.plate || driver.vehicleName || driver.color" class="flex flex-wrap gap-2 mb-4">
                        <span v-if="driver.plate" class="inline-flex items-center gap-1.5 text-xs font-black text-gray-700 bg-white border border-gray-200 px-3 py-1.5 rounded-xl">
                          <span class="material-symbols-rounded text-[16px] text-teal-600">pin</span>{{ driver.plate }}
                        </span>
                        <span v-if="driver.vehicleName" class="inline-flex items-center gap-1.5 text-xs font-black text-gray-700 bg-white border border-gray-200 px-3 py-1.5 rounded-xl">
                          <span class="material-symbols-rounded text-[16px] text-teal-600">airport_shuttle</span>{{ driver.vehicleName }}
                        </span>
                        <span v-if="driver.color" class="inline-flex items-center gap-1.5 text-xs font-black text-gray-700 bg-white border border-gray-200 px-3 py-1.5 rounded-xl">
                          <span class="material-symbols-rounded text-[16px] text-teal-600">palette</span>{{ driver.color }}
                        </span>
                      </div>

                      <a v-if="driver.phone" :href="`tel:${driver.phone}`"
                        class="inline-flex items-center gap-2 bg-teal-600 text-white font-black text-sm px-4 py-2.5 rounded-xl hover:bg-teal-700 active:scale-95 transition-all">
                        <span class="material-symbols-rounded text-[18px]">call</span>
                        {{ formatPhone(driver.phone) }}
                      </a>
                    </div>
                  </div>
                </div>

                <!-- Staff Grid -->
                <div v-if="assignedStaff.length">
                  <p class="text-[11px] font-black text-gray-400 uppercase tracking-widest mb-3 flex items-center gap-2">
                    <span class="material-symbols-rounded text-[18px] text-teal-500">groups</span>
                    เจ้าหน้าที่ประจำทริป · {{ assignedStaff.length }} ท่าน
                  </p>
                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div v-for="s in assignedStaff" :key="s.id"
                      class="flex items-center gap-4 rounded-2xl border border-gray-100 bg-gray-50/60 p-4 hover:border-teal-200 hover:bg-white transition-all duration-300">
                      <!-- Avatar -->
                      <div class="shrink-0">
                        <img v-if="s.avatar_url" :src="s.avatar_url" :alt="s.name"
                          class="w-14 h-14 rounded-2xl object-cover border-2 border-white" />
                        <div v-else class="w-14 h-14 rounded-2xl bg-gradient-to-br from-teal-500 to-teal-700 text-white flex items-center justify-center border-2 border-white">
                          <span class="text-base font-black">{{ initials(s.name) }}</span>
                        </div>
                      </div>
                      <div class="flex-1 min-w-0">
                        <p class="font-black text-gray-900 leading-tight truncate">{{ s.name }}</p>
                        <p class="text-xs font-bold text-gray-400 mb-2">
                          <span v-if="s.nickname">({{ s.nickname }}) · </span>ทีมงานดูแลทริป
                        </p>
                        <a v-if="s.phone" :href="`tel:${s.phone}`"
                          class="inline-flex items-center gap-1.5 text-xs font-black text-teal-600 bg-teal-50 px-3 py-1.5 rounded-lg hover:bg-teal-600 hover:text-white active:scale-95 transition-all border border-teal-100">
                          <span class="material-symbols-rounded text-[16px]">call</span>
                          {{ formatPhone(s.phone) }}
                        </a>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Reassurance footer -->
                <div class="flex items-start gap-2.5 pt-1 text-gray-400">
                  <span class="material-symbols-rounded text-[18px] mt-0.5 text-teal-500">verified_user</span>
                  <p class="text-xs font-bold leading-relaxed">ทีมงานทุกคนผ่านการอบรมและพร้อมดูแลความปลอดภัยของคุณ หากมีข้อสงสัยก่อนเดินทาง สามารถติดต่อได้โดยตรง</p>
                </div>
              </div>
            </section>

            <!-- Support / Help Area -->
            <section class="bg-white rounded-[2rem] border border-gray-100 p-8 group">
              <div class="flex flex-col sm:flex-row items-center gap-6 text-center sm:text-left">
                <div class="w-16 h-16 bg-gray-50 rounded-2.5rem flex items-center justify-center border border-gray-100 group-hover:scale-110 group-hover:bg-teal-50 group-hover:border-teal-100 transition-all duration-500">
                  <span class="material-symbols-rounded text-teal-600 text-[32px]">support_agent</span>
                </div>
                <div class="flex-1">
                  <h5 class="text-gray-900 font-black text-lg mb-1">มีปัญหาเกี่ยวกับการจอง?</h5>
                  <p class="text-gray-400 font-bold text-xs mb-4">ฝ่ายบริการลูกค้าของเราพร้อมช่วยเหลือคุณตลอด 24 ชม.</p>
                  <a href="#" class="inline-flex items-center gap-2 text-teal-600 font-black text-sm bg-teal-50 px-5 py-2.5 rounded-xl hover:bg-teal-600 hover:text-white transition-all duration-300 border border-teal-100">
                    ติดต่อฝ่ายบริการลูกค้า
                    <span class="material-symbols-rounded text-sm">arrow_forward</span>
                  </a>
                </div>
              </div>
            </section>
          </div>

          <!-- Right Column: QR Code & Guidance -->
          <div class="lg:col-span-5 space-y-8 animate-in fade-in slide-in-from-right-8 duration-700 delay-500 fill-mode-both">
            
            <!-- QR Ticket Card (MAIN FOCUS) -->
            <section v-if="booking.qr_code && booking.status === 'confirmed'" class="bg-white rounded-[2.5rem] border-2 border-teal-500/10 p-8 md:p-10 flex flex-col items-center text-center relative overflow-hidden group">
              <!-- Success Badge Overlay -->
              <div class="absolute -right-8 -top-8 w-32 h-32 bg-teal-600/5 rounded-full blur-3xl group-hover:bg-teal-600/10 transition-colors duration-700"></div>
              
              <div class="relative z-10 w-full mb-8">
                <h3 class="text-2xl font-black text-gray-900 mb-2">ใช้ QR นี้สำหรับเช็คอิน</h3>
                <p class="text-teal-600/70 font-bold text-sm tracking-wide">โปรดแสดงรหัสนี้แก่เจ้าหน้าที่เมื่อถึงจุดนัดหมาย</p>
              </div>

              <!-- QR Visual Container -->
              <div class="relative z-10 p-6 bg-white rounded-[2.5rem] border-2 border-dashed border-teal-100 group-hover:border-teal-400 transition-all duration-500 mb-8 scale-100 group-hover:scale-[1.02]">
                <canvas ref="qrCanvas" class="mx-auto block" style="image-rendering:pixelated"></canvas>
                
                <!-- If checked in overlay -->
                <div v-if="booking.checked_in" class="absolute inset-0 bg-white/90 backdrop-blur-sm flex flex-col items-center justify-center p-6 rounded-[2.5rem] animate-in fade-in zoom-in duration-500">
                  <div class="w-20 h-20 bg-green-600 text-white rounded-full flex items-center justify-center mb-4 animate-bounce">
                    <span class="material-symbols-rounded text-[48px]">verified</span>
                  </div>
                  <p class="font-black text-green-700 text-xl">เช็คอินเรียบร้อย</p>
                  <p class="text-green-600/60 font-bold text-xs mt-1">{{ new Date(booking.checked_in_at).toLocaleString('th-TH') }}</p>
                </div>
              </div>

              <!-- QR Code Text Ref -->
              <div class="relative z-10 max-w-full overflow-x-auto whitespace-nowrap bg-gray-50 px-6 py-2.5 rounded-2xl border border-gray-100 mb-8 font-mono font-black text-teal-800 text-lg tracking-widest group-hover:bg-teal-50 group-hover:border-teal-100 transition-colors">
                {{ booking.qr_code }}
              </div>

              <!-- Primary Action: Download -->
              <button @click="saveQR"
                class="w-full relative z-10 flex items-center justify-center gap-3 px-8 py-5 bg-teal-600 text-white font-black text-lg rounded-[1.5rem] hover:bg-teal-700 hover:-translate-y-1 active:scale-95 transition-all duration-300 group/btn">
                <span class="material-symbols-rounded text-[24px] group-hover/btn:animate-bounce">download</span>
                <span>บันทึก QR Code สำหรับเช็คอิน</span>
              </button>
              
              <p class="mt-6 text-[11px] font-black text-gray-400 uppercase tracking-[0.2em] relative z-10 flex items-center gap-2 justify-center">
                <span class="material-symbols-rounded text-sm">info</span>
                กรุณาเก็บ QR นี้ไว้เพื่อใช้ในวันเดินทาง
              </p>
            </section>

            <!-- Next Steps Guide -->
            <section v-if="booking.status === 'confirmed'" class="bg-amber-50/50 rounded-[2rem] border border-amber-100 p-8 space-y-6">
              <h4 class="text-amber-800 font-black text-lg flex items-center gap-2.5">
                <span class="material-symbols-rounded" style="font-variation-settings:'FILL' 1">bolt</span>
                ขั้นตอนถัดไป
              </h4>
              <div class="space-y-4">
                <div class="flex items-start gap-4">
                  <div class="w-8 h-8 rounded-full bg-amber-200 flex items-center justify-center text-amber-900 font-black text-sm shrink-0">1</div>
                  <p class="text-amber-900/80 font-bold text-sm leading-relaxed">บันทึกรูปภาพ QR Code ด้านบนลงในโทรศัพท์มือถือของคุณ</p>
                </div>
                <div class="flex items-start gap-4">
                  <div class="w-8 h-8 rounded-full bg-amber-200 flex items-center justify-center text-amber-900 font-black text-sm shrink-0">2</div>
                  <p class="text-amber-900/80 font-bold text-sm leading-relaxed">เดินทางไปถึงจุดนัดพบอย่างน้อย 15-30 นาทีก่อนเวลาออกเดินทาง</p>
                </div>
                <div class="flex items-start gap-4">
                  <div class="w-8 h-8 rounded-full bg-amber-200 flex items-center justify-center text-amber-900 font-black text-sm shrink-0">3</div>
                  <p class="text-amber-900/80 font-bold text-sm leading-relaxed">แสดง QR Code ให้ทีมงานสแกนเพื่อยืนยันการเช็คอินขึ้นรถ</p>
                </div>
              </div>
            </section>

            <!-- More Actions (Secondary/Tertiary) -->
            <div class="flex flex-col gap-3 pt-4">
              <router-link to="/my-bookings"
                class="w-full flex items-center justify-center gap-2 py-4 bg-white border-2 border-gray-100 text-gray-700 rounded-2xl font-black text-base hover:bg-gray-50 hover:border-gray-200 active:scale-95 transition-all duration-300">
                <span>ดูประวัติการจองทั้งหมด</span>
                <span class="material-symbols-rounded text-[20px]">history</span>
              </router-link>
              <router-link to="/trips"
                class="w-full flex items-center justify-center gap-2 py-4 text-teal-600 font-black text-sm hover:bg-teal-50 rounded-2xl transition-all duration-300 underline underline-offset-4 decoration-teal-600/30">
                <span class="material-symbols-rounded text-[20px]">explore</span>
                <span>ค้นหากิจกรรมที่น่าสนใจเพิ่มเติม</span>
              </router-link>
            </div>
          </div>
        </div>

      </div>
    </main>

    <!-- Not Found -->
    <div v-else class="grow flex items-center justify-center text-center py-16 relative z-10">
      <div class="bg-white p-12 rounded-[3rem] border border-gray-100 flex flex-col items-center max-w-sm mx-4">
        <div class="w-24 h-24 bg-red-50 rounded-full flex items-center justify-center mb-6 text-red-400">
          <span class="material-symbols-rounded text-[56px]" style="font-variation-settings:'wght' 300">error</span>
        </div>
        <h3 class="text-gray-900 font-black text-2xl mb-2">ไม่พบข้อมูลการจอง</h3>
        <p class="text-gray-400 font-bold text-sm mb-8 leading-relaxed">รหัสการจองอาจไม่ถูกต้อง หรือถูกลบออกจากระบบ กรุณาตรวจสอบอีกครั้ง</p>
        <router-link to="/trips" class="w-full bg-teal-600 text-white py-4 rounded-2xl font-black text-base hover:bg-teal-700 transition-all">
          กลับไปหน้าทริปทั้งหมด
        </router-link>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '../lib/axios';
import QRCode from 'qrcode';

const route = useRoute();
const booking = ref(null);
const loading = ref(true);
const qrCanvas = ref(null);

const statusMap = { pending: 'รอชำระเงิน', confirmed: 'ยืนยันแล้ว', cancelled: 'ยกเลิกแล้ว', refunded: 'คืนเงินแล้ว' };
const statusLabel = computed(() => statusMap[booking.value?.status] || booking.value?.status);

const pickupLabel = computed(() => {
  const pt = booking.value?.pickup_point;
  if (pt) {
    return `${pt.region_label} — ${pt.pickup_location}`;
  }
  const region = booking.value?.pickup_region;
  if (region) {
    const pts = booking.value?.schedule?.pickup_points || [];
    const schedulePt = pts.find(p => p.region === region);
    return schedulePt ? `${schedulePt.region_label} — ${schedulePt.pickup_location}` : region;
  }
  // จุดรับที่ลูกค้าปักหมุดเอง (ไม่มีจุดรับตายตัว/ภูมิภาค)
  const cp = booking.value?.custom_pickup;
  return cp ? cp.label : '';
});

// จุดรับปักหมุดเอง (ไม่มีจุดรับตายตัวหรือภูมิภาค)
const isCustomPickup = computed(() =>
  Boolean(booking.value?.custom_pickup && !booking.value?.pickup_point && !booking.value?.pickup_region)
);

const passengerPickupLabel = (p) => {
  const pt = p?.pickup_point;
  if (pt) return `${pt.region_label} — ${pt.pickup_location}`;
  return null;
};

const hasVariedPassengerPickups = computed(() => {
  const passengers = booking.value?.passengers || [];
  if (passengers.length <= 1) return false;
  const ids = passengers.map(p => p.pickup_point_id).filter(Boolean);
  if (!ids.length) return false;
  return new Set(ids).size > 1;
});

// ── Trip team: staff & driver ──
const assignedStaff = computed(() => booking.value?.assigned_staff || []);

const driver = computed(() => {
  const v = booking.value?.schedule?.vehicle;
  if (!v || !v.driver_name) return null;
  return {
    name: v.driver_name,
    phone: v.driver_phone,
    photo: v.driver_photo,
    plate: v.license_plate,
    color: v.color,
    vehicleName: v.name,
  };
});

const hasTeam = computed(() => assignedStaff.value.length > 0 || !!driver.value);

function initials(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return '?';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[1][0]).toUpperCase();
}

function formatPhone(phone) {
  const digits = String(phone || '').replace(/\D/g, '');
  if (digits.length === 10) return `${digits.slice(0, 3)}-${digits.slice(3, 6)}-${digits.slice(6)}`;
  return phone;
}

// ── Installment helpers ──
const paidInstallmentsCount = computed(() =>
  (booking.value?.installment_payments || []).filter(i => i.status === 'paid').length
);
const allInstallmentsPaid = computed(() =>
  paidInstallmentsCount.value === booking.value?.installment_count
);
const nextPendingInstallment = computed(() =>
  (booking.value?.installment_payments || []).find(i => i.status !== 'paid')
);
function isNextInstallment(inst) {
  return nextPendingInstallment.value?.installment_no === inst.installment_no;
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' });
}

function passengerNameParts(passenger) {
  const name = String(passenger?.name || '').trim();
  if (!name) return { firstName: '-', lastName: '-' };

  const parts = name.split(/\s+/);
  return {
    firstName: parts[0] || '-',
    lastName: parts.slice(1).join(' ') || '-',
  };
}

function passengerDisplayName(passenger) {
  return [passenger?.title, passenger?.name].filter(Boolean).join(' ') || '-';
}

async function renderQrCode() {
  await nextTick();
  if (qrCanvas.value && booking.value?.qr_code) {
    try {
      await QRCode.toCanvas(qrCanvas.value, booking.value.qr_code, {
        width: 240,
        margin: 2,
        color: { dark: '#0d2b1e', light: '#ffffff' }, // Darker forest green for premium feel
      });
    } catch (e) {
      console.error('QR render error:', e);
    }
  }
}

function saveQR() {
  if (!qrCanvas.value) return;
  const link = document.createElement('a');
  link.download = `luilaykhao-ticket-${booking.value?.booking_ref || 'qr'}.png`;
  link.href = qrCanvas.value.toDataURL('image/png');
  link.click();
}

watch(() => booking.value?.qr_code, (val) => {
  if (val) renderQrCode();
});

onMounted(async () => {
  try {
    const res = await api.get(`/bookings/${route.params.bookingRef}`);
    booking.value = res.data.data;
    if (booking.value?.qr_code) {
      renderQrCode();
    }
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  height: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: #e2e8f0;
  border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: #cbd5e1;
}

@keyframes float {
  0% { transform: translateY(0px); }
  50% { transform: translateY(-10px); }
  100% { transform: translateY(0px); }
}

.hover-float:hover {
  animation: float 3s ease-in-out infinite;
}
</style>
