<template>
  <!-- Loading -->
  <div v-if="loading" class="flex items-center justify-center min-h-[60vh]">
    <div class="flex flex-col items-center gap-4">
      <div class="w-12 h-12 rounded-full border-4 border-[#b4eae9] border-t-[#006565] animate-spin"></div>
      <p class="text-[#6e7979] font-['DB Heavent', 'Anuphan']">กำลังโหลด...</p>
    </div>
  </div>

  <!-- No booking -->
  <div v-else-if="!booking" class="flex flex-col items-center justify-center min-h-[60vh] text-[#6e7979] font-['DB Heavent', 'Anuphan']">
    <span class="material-symbols-rounded text-6xl mb-4 text-[#bdc9c8]">sentiment_dissatisfied</span>
    <p class="text-lg">ไม่พบข้อมูลการจอง</p>
  </div>

  <!-- Main Content -->
  <div v-else class="font-['DB Heavent', 'Anuphan'] bg-[#f9f9f9] min-h-screen pt-8 pb-24 px-4 md:px-8 lg:px-12">
    <!-- Progress Stepper -->
    <div class="flex items-center justify-center mb-12 max-w-7xl mx-auto">
      <div class="flex items-center w-full max-w-2xl">
        <div class="flex flex-col items-center flex-1">
          <div class="w-10 h-10 rounded-full bg-[#b4eae9] text-[#376b6a] flex items-center justify-center mb-2 font-bold text-sm">1</div>
          <span class="text-xs font-medium text-[#6e7979]">เลือกทริป</span>
        </div>
        <div class="h-[2px] flex-1 bg-[#b4eae9]"></div>
        <div class="flex flex-col items-center flex-1">
          <div class="w-10 h-10 rounded-full bg-[#b4eae9] text-[#376b6a] flex items-center justify-center mb-2 font-bold text-sm">2</div>
          <span class="text-xs font-medium text-[#6e7979]">รายละเอียด</span>
        </div>
        <div class="h-[2px] flex-1 bg-[#006565]"></div>
        <div class="flex flex-col items-center flex-1">
          <div class="w-10 h-10 rounded-full bg-[#006565] text-white flex items-center justify-center mb-2 font-bold text-sm ring-4 ring-[#93f2f2]/40">3</div>
          <span class="text-xs font-bold text-[#006565]">ชำระเงิน</span>
        </div>
      </div>
    </div>

    <!-- Urgency Message & Timer -->
    <div v-if="seatsStore.countdownSeconds > 0" class="max-w-7xl mx-auto mb-10">
      <div class="bg-red-50 border border-red-100 rounded-3xl p-5 md:p-6 mb-4 flex flex-col md:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center shrink-0">
            <span class="material-symbols-rounded text-red-600 text-2xl animate-pulse">crisis_alert</span>
          </div>
          <div>
            <h3 class="text-red-900 font-bold text-base md:text-lg">กรุณาชำระเงินเพื่อยืนยันสิทธิ์</h3>
            <p class="text-red-700 text-sm">เราจะสำรองที่นั่งให้คุณเป็นเวลา {{ paymentWindowMinutes }} นาที มิฉะนั้นรายการจะถูกยกเลิกโดยอัตโนมัติ</p>
          </div>
        </div>
        <div class="bg-white px-6 py-3 rounded-2xl border border-red-200">
          <CountdownTimer :seconds="seatsStore.countdownSeconds" />
        </div>
      </div>
    </div>

    <!-- Two-column layout -->
    <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-12 gap-10 items-start">

      <!-- LEFT: Payment Flow -->
      <div class="lg:col-span-8 space-y-8 pb-10">

        <!-- ── Step Instructions ── -->
        <div class="bg-white rounded-3xl p-6 md:p-8 border border-gray-100">
           <h2 class="text-xl font-bold mb-8 text-gray-900 flex items-center gap-2">
            <span class="material-symbols-rounded text-teal-600">checklist</span>
            ขั้นตอนการชำระเงิน
          </h2>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6 relative">
            <!-- Connector lines (Desktop) -->
            <div class="hidden md:block absolute top-7 left-[15%] right-[15%] h-[2px] bg-gray-100"></div>
            
            <div class="relative flex flex-col items-center text-center gap-3">
              <div class="w-14 h-14 rounded-2xl bg-teal-50 text-teal-600 flex items-center justify-center font-black text-xl border-2 border-teal-100 z-10">1</div>
              <p class="font-bold text-gray-900">สแกน QR เพื่อชำระเงิน</p>
              <p class="text-xs text-gray-500 leading-relaxed px-2">เปิดแอปธนาคารแล้วสแกน QR Code ด้านล่าง</p>
            </div>
            <div class="relative flex flex-col items-center text-center gap-3">
              <div class="w-14 h-14 rounded-2xl bg-teal-50 text-teal-600 flex items-center justify-center font-black text-xl border-2 border-teal-100 z-10">2</div>
              <p class="font-bold text-gray-900">อัปโหลดสลิป</p>
              <p class="text-xs text-gray-500 leading-relaxed px-2">แนบหลักฐานการโอนเงินเพื่อตรวจสอบความถูกต้อง</p>
            </div>
            <div class="relative flex flex-col items-center text-center gap-3">
              <div class="w-14 h-14 rounded-2xl bg-teal-50 text-teal-600 flex items-center justify-center font-black text-xl border-2 border-teal-100 z-10">3</div>
              <p class="font-bold text-gray-900">กดยืนยันการชำระเงิน</p>
              <p class="text-xs text-gray-500 leading-relaxed px-2">เสร็จสิ้น! รอเจ้าหน้าที่ตรวจสอบใน 10 นาที</p>
            </div>
          </div>
        </div>

        <!-- ── Payment Type Selection (show only if installment or deposit available) ── -->
        <section v-if="installmentAvailable || installmentNotAvailable || depositAvailable" class="bg-white rounded-3xl p-5 sm:p-8 border border-gray-100">
          <!-- Section Header -->
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-6">
            <div class="flex items-center gap-3">
              <div class="w-11 h-11 rounded-2xl bg-gradient-to-br from-teal-500 to-emerald-500 flex items-center justify-center shrink-0">
                <span class="material-symbols-rounded text-white text-[22px]">credit_card</span>
              </div>
              <div>
                <h2 class="text-lg sm:text-xl font-black text-gray-900 leading-tight">เลือกรูปแบบการชำระเงิน</h2>
                <p class="text-xs text-gray-500 font-medium">เลือกแบบที่สะดวกที่สุดสำหรับท่าน</p>
              </div>
            </div>
            <span class="text-[10px] font-black text-teal-700 bg-teal-50 border border-teal-100 px-3 py-1.5 rounded-full uppercase tracking-widest self-start sm:self-auto">
              {{ payOptionsCount }} ตัวเลือก
            </span>
          </div>

          <!-- Payment Type Cards -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3 sm:gap-4">

            <!-- Full Payment -->
            <button @click="paymentType = 'full'"
              class="group relative flex flex-col gap-3 p-5 border-2 rounded-2xl text-left transition-all duration-300 overflow-hidden"
              :class="paymentType === 'full'
                ? 'border-emerald-500 bg-gradient-to-br from-emerald-50 to-teal-50/50 scale-[1.01]'
                : 'border-gray-100 bg-white hover:border-emerald-200 hover:bg-emerald-50/30 hover:-translate-y-0.5'">

              <!-- Recommended Ribbon -->
              <div v-if="paymentType !== 'full'" class="absolute top-0 right-0 bg-emerald-500 text-white text-[9px] font-black px-2.5 py-1 rounded-bl-xl uppercase tracking-wider">
                ⭐ แนะนำ
              </div>
              <!-- Selected check -->
              <div v-if="paymentType === 'full'" class="absolute top-3 right-3 w-7 h-7 rounded-full bg-emerald-500 flex items-center justify-center ring-4 ring-emerald-100">
                <span class="material-symbols-rounded text-white text-[18px]">check</span>
              </div>

              <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-2xl flex items-center justify-center transition-all shrink-0"
                  :class="paymentType === 'full' ? 'bg-emerald-500 text-white' : 'bg-gray-100 text-gray-400 group-hover:bg-emerald-100 group-hover:text-emerald-600'">
                  <span class="material-symbols-rounded text-[26px]" style="font-variation-settings:'FILL' 1">payments</span>
                </div>
                <div>
                  <p class="font-black text-base" :class="paymentType === 'full' ? 'text-emerald-900' : 'text-gray-900'">ชำระเต็มจำนวน</p>
                  <p class="text-[11px] font-bold text-emerald-600 uppercase tracking-widest">ครั้งเดียวจบ</p>
                </div>
              </div>

              <div class="border-t border-dashed pt-3 mt-1" :class="paymentType === 'full' ? 'border-emerald-200' : 'border-gray-100'">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">ยอดที่ต้องชำระ</p>
                <p class="text-2xl font-black leading-none" :class="paymentType === 'full' ? 'text-emerald-700' : 'text-gray-900'">
                  ฿{{ Number(booking.total_amount).toLocaleString() }}
                </p>
              </div>

              <ul class="text-[12px] text-gray-600 space-y-1 mt-1">
                <li class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-emerald-500 text-[14px]">check_circle</span>
                  จบในครั้งเดียว ไม่ต้องจำกำหนด
                </li>
                <li class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-emerald-500 text-[14px]">check_circle</span>
                  ยืนยันที่นั่งทันที
                </li>
              </ul>
            </button>

            <!-- Deposit Payment -->
            <button v-if="depositAvailable" @click="paymentType = 'deposit'"
              class="group relative flex flex-col gap-3 p-5 border-2 rounded-2xl text-left transition-all duration-300 overflow-hidden"
              :class="paymentType === 'deposit'
                ? 'border-teal-600 bg-gradient-to-br from-teal-50 to-cyan-50/50 scale-[1.01]'
                : 'border-gray-100 bg-white hover:border-teal-200 hover:bg-teal-50/30 hover:-translate-y-0.5'">

              <div v-if="paymentType !== 'deposit'" class="absolute top-0 right-0 bg-teal-600 text-white text-[9px] font-black px-2.5 py-1 rounded-bl-xl uppercase tracking-wider">
                🔥 ยอดนิยม
              </div>
              <div v-if="paymentType === 'deposit'" class="absolute top-3 right-3 w-7 h-7 rounded-full bg-teal-600 flex items-center justify-center ring-4 ring-teal-100">
                <span class="material-symbols-rounded text-white text-[18px]">check</span>
              </div>

              <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-2xl flex items-center justify-center transition-all shrink-0"
                  :class="paymentType === 'deposit' ? 'bg-teal-600 text-white' : 'bg-gray-100 text-gray-400 group-hover:bg-teal-100 group-hover:text-teal-700'">
                  <span class="material-symbols-rounded text-[26px]" style="font-variation-settings:'FILL' 1">savings</span>
                </div>
                <div>
                  <p class="font-black text-base" :class="paymentType === 'deposit' ? 'text-teal-900' : 'text-gray-900'">จ่ายมัดจำ</p>
                  <p class="text-[11px] font-bold text-teal-600 uppercase tracking-widest">จ่าย 2 ครั้ง</p>
                </div>
              </div>

              <div class="border-t border-dashed pt-3 mt-1" :class="paymentType === 'deposit' ? 'border-teal-200' : 'border-gray-100'">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">มัดจำตอนนี้</p>
                <p class="text-2xl font-black leading-none" :class="paymentType === 'deposit' ? 'text-teal-700' : 'text-gray-900'">
                  ฿{{ depositAmount.toLocaleString() }}
                </p>
                <p class="text-[11px] font-bold text-amber-700 mt-1.5">
                  + ส่วนที่เหลือ ฿{{ balanceAmount.toLocaleString() }}
                </p>
              </div>

              <ul class="text-[12px] text-gray-600 space-y-1 mt-1">
                <li class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-teal-500 text-[14px]">check_circle</span>
                  จ่ายส่วนที่เหลือก่อนเดินทาง 15 วัน
                </li>
                <li class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-teal-500 text-[14px]">check_circle</span>
                  มี SMS/อีเมล แจ้งเตือนอัตโนมัติ
                </li>
              </ul>
            </button>

            <!-- Installment Payment -->
            <button v-if="installmentAvailable || installmentNotAvailable" @click="!installmentNotAvailable && (paymentType = 'installment')"
              class="group relative flex flex-col gap-3 p-5 border-2 rounded-2xl text-left transition-all duration-300 overflow-hidden"
              :class="installmentNotAvailable
                ? 'border-gray-100 bg-gray-50 opacity-60 cursor-not-allowed'
                : paymentType === 'installment'
                  ? 'border-amber-500 bg-gradient-to-br from-amber-50 to-orange-50/50 scale-[1.01]'
                  : 'border-gray-100 bg-white hover:border-amber-200 hover:bg-amber-50/30 hover:-translate-y-0.5'">

              <div v-if="installmentNotAvailable" class="absolute top-0 right-0 bg-gray-400 text-white text-[9px] font-black px-2.5 py-1 rounded-bl-xl uppercase tracking-wider">
                ไม่พร้อมใช้
              </div>
              <div v-else-if="paymentType !== 'installment'" class="absolute top-0 right-0 bg-amber-500 text-white text-[9px] font-black px-2.5 py-1 rounded-bl-xl uppercase tracking-wider">
                💳 ผ่อน 0%
              </div>
              <div v-if="paymentType === 'installment' && !installmentNotAvailable" class="absolute top-3 right-3 w-7 h-7 rounded-full bg-amber-500 flex items-center justify-center ring-4 ring-amber-100">
                <span class="material-symbols-rounded text-white text-[18px]">check</span>
              </div>

              <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-2xl flex items-center justify-center transition-all shrink-0"
                  :class="paymentType === 'installment' ? 'bg-amber-500 text-white' : 'bg-gray-100 text-gray-400 group-hover:bg-amber-100 group-hover:text-amber-600'">
                  <span class="material-symbols-rounded text-[26px]" style="font-variation-settings:'FILL' 1">calendar_month</span>
                </div>
                <div>
                  <p class="font-black text-base" :class="installmentNotAvailable ? 'text-gray-400' : paymentType === 'installment' ? 'text-amber-900' : 'text-gray-900'">ผ่อนชำระ</p>
                  <p class="text-[11px] font-bold uppercase tracking-widest" :class="installmentNotAvailable ? 'text-gray-400' : 'text-amber-600'">
                    <template v-if="installmentNotAvailable">ทริปใกล้เกินไป</template>
                    <template v-else>{{ availableInstallmentOptions[0] }}–{{ availableInstallmentOptions[availableInstallmentOptions.length - 1] || 6 }} งวด</template>
                  </p>
                </div>
              </div>

              <div class="border-t border-dashed pt-3 mt-1" :class="paymentType === 'installment' ? 'border-amber-200' : 'border-gray-100'">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-1">งวดละประมาณ</p>
                <p class="text-2xl font-black leading-none" :class="paymentType === 'installment' ? 'text-amber-700' : 'text-gray-900'">
                  ฿{{ minPerInstallmentPreview.toLocaleString() }}
                </p>
                <p class="text-[11px] font-bold text-gray-500 mt-1.5">
                  ทุก ~{{ installmentIntervalDays }} วัน · ไม่มีดอกเบี้ย
                </p>
              </div>

              <ul class="text-[12px] text-gray-600 space-y-1 mt-1">
                <li class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-amber-500 text-[14px]">check_circle</span>
                  ระบบแบ่งงวดให้จากวันเดินทาง
                </li>
                <li class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-amber-500 text-[14px]">check_circle</span>
                  ยอดรวมคงเดิม ไม่มีดอกเบี้ย
                </li>
              </ul>
            </button>
          </div>

          <!-- Installment not available warning -->
          <Transition name="fade">
            <div v-if="installmentWarningMessage" class="mt-4 flex items-start gap-3 p-4 bg-amber-50 border border-amber-200 rounded-2xl">
              <span class="material-symbols-rounded text-amber-500 text-xl shrink-0">warning</span>
              <p class="text-sm font-medium text-amber-800 leading-relaxed">{{ installmentWarningMessage }}</p>
            </div>
          </Transition>

          <!-- Deposit details + cancellation clause -->
          <Transition name="fade">
          <div v-if="paymentType === 'deposit'" class="mt-6 space-y-4">
            <!-- Summary breakdown -->
            <div class="bg-gradient-to-br from-teal-50 via-white to-cyan-50/50 border-2 border-teal-100 rounded-3xl p-5 sm:p-6">
              <div class="flex items-center gap-2 mb-4">
                <span class="material-symbols-rounded text-teal-600 text-xl" style="font-variation-settings:'FILL' 1">savings</span>
                <h3 class="font-black text-teal-900 text-sm uppercase tracking-tight">สรุปยอดมัดจำ</h3>
              </div>

              <!-- Three Steps Visual -->
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 relative">
                <!-- Step 1: Total -->
                <div class="bg-white rounded-2xl p-4 border border-gray-100">
                  <div class="flex items-center gap-2 mb-2">
                    <div class="w-6 h-6 rounded-full bg-gray-100 text-gray-500 flex items-center justify-center text-[11px] font-black">1</div>
                    <p class="text-[10px] font-black text-gray-500 uppercase tracking-widest">ยอดรวมทั้งหมด</p>
                  </div>
                  <p class="text-xl font-black text-gray-900">฿{{ Number(booking.total_amount).toLocaleString() }}</p>
                  <p class="text-[11px] text-gray-500 mt-1">ราคารวมทั้งหมดของทริปนี้</p>
                </div>

                <!-- Step 2: Deposit (highlight) -->
                <div class="bg-white rounded-2xl p-4 border-2 border-teal-500 relative">
                  <div class="absolute -top-2.5 left-4 bg-teal-600 text-white text-[9px] font-black px-2 py-0.5 rounded-full uppercase tracking-widest">ชำระตอนนี้</div>
                  <div class="flex items-center gap-2 mb-2 mt-1">
                    <div class="w-6 h-6 rounded-full bg-teal-600 text-white flex items-center justify-center text-[11px] font-black">2</div>
                    <p class="text-[10px] font-black text-teal-700 uppercase tracking-widest">มัดจำ</p>
                  </div>
                  <p class="text-2xl font-black text-teal-700">฿{{ depositAmount.toLocaleString() }}</p>
                  <p class="text-[11px] text-teal-600 mt-1 font-bold">
                    {{ depositPercentText }}
                  </p>
                </div>

                <!-- Step 3: Balance -->
                <div class="bg-white rounded-2xl p-4 border border-amber-200">
                  <div class="flex items-center gap-2 mb-2">
                    <div class="w-6 h-6 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center text-[11px] font-black">3</div>
                    <p class="text-[10px] font-black text-amber-600 uppercase tracking-widest">ส่วนที่เหลือ</p>
                  </div>
                  <p class="text-xl font-black text-amber-700">฿{{ balanceAmount.toLocaleString() }}</p>
                  <p class="text-[11px] text-amber-600 mt-1 font-bold">
                    <span class="material-symbols-rounded text-[12px] align-middle">event</span>
                    ภายใน {{ balanceDueDateText }}
                  </p>
                </div>
              </div>

              <!-- Timeline note -->
              <div class="mt-4 flex items-center gap-2 bg-white/70 rounded-xl p-3 border border-teal-100">
                <span class="material-symbols-rounded text-teal-600 text-[18px]" style="font-variation-settings:'FILL' 1">notifications_active</span>
                <p class="text-[12px] text-teal-800 font-medium leading-snug">
                  เราจะส่ง <strong>SMS + อีเมล</strong> แจ้งเตือนล่วงหน้า <strong>5 วัน, 2 วัน และวันครบกำหนด</strong> เพื่อให้ท่านไม่พลาดการชำระ
                </p>
              </div>
            </div>

            <!-- No-refund Cancellation Clause -->
            <div class="bg-gradient-to-br from-red-50 to-rose-50/50 border-2 border-red-200 rounded-3xl p-5 sm:p-6">
              <div class="flex flex-col sm:flex-row gap-4">
                <div class="w-12 h-12 rounded-2xl bg-red-600 flex items-center justify-center shrink-0 self-start">
                  <span class="material-symbols-rounded text-white text-[26px]" style="font-variation-settings:'FILL' 1">gavel</span>
                </div>
                <div class="flex-1">
                  <div class="flex items-center gap-2 mb-2">
                    <p class="font-black text-base text-red-900 uppercase tracking-tight">เงื่อนไขสำคัญ</p>
                    <span class="text-[10px] font-black text-red-700 bg-red-100 border border-red-200 px-2 py-0.5 rounded-full uppercase tracking-widest">กรุณาอ่าน</span>
                  </div>
                  <p class="text-[13px] sm:text-sm text-red-800 leading-relaxed">
                    กรณีขอยกเลิกการเดินทาง ทางทริปขอสงวนสิทธิ์ <strong class="text-red-900 underline decoration-wavy decoration-red-400 underline-offset-4">ไม่คืนเงินมัดจำทุกกรณี</strong>
                    เนื่องจากมีการนำไปสำรองจ่ายค่าอุทยานและยานพาหนะล่วงหน้า
                  </p>
                  <div class="mt-3 flex items-start gap-2 bg-white/70 rounded-xl p-3 border border-red-100">
                    <span class="material-symbols-rounded text-red-500 text-[18px] shrink-0 mt-0.5">schedule</span>
                    <p class="text-[12px] text-red-800 font-medium leading-snug">
                      ต้องชำระยอดส่วนที่เหลือ <strong class="text-red-900">ก่อนเดินทาง 15 วัน</strong> (ภายในวันที่ <strong>{{ balanceDueDateText }}</strong>) มิฉะนั้นถือว่าสละสิทธิ์การเดินทาง
                    </p>
                  </div>
                </div>
              </div>
            </div>
          </div>
          </Transition>

          <!-- Installment Count Selector -->
          <div v-if="paymentType === 'installment'" class="mt-6 p-5 bg-amber-50/50 border border-amber-100 rounded-2xl">
            <label class="block text-sm font-black text-amber-900 mb-3 flex items-center gap-2">
              <span class="material-symbols-rounded text-amber-500 text-lg">tune</span>
              แบ่งได้ถึง {{ maxInstallmentCount }} งวด — เลือกได้ตามสะดวก
            </label>
            <div class="flex gap-2">
              <button v-for="n in availableInstallmentOptions" :key="n" @click="selectedInstallmentCount = n"
                class="flex-1 py-3 px-2 rounded-xl border-2 text-center transition-all font-black text-sm"
                :class="selectedInstallmentCount === n
                  ? 'border-amber-500 bg-amber-500 text-white'
                  : 'border-gray-200 bg-white text-gray-600 hover:border-amber-300'">
                {{ n }} งวด
              </button>
            </div>
            <div class="mt-3 flex flex-wrap justify-between items-center gap-1 text-xs text-amber-700">
              <span>งวดละ <strong class="text-amber-900">฿{{ perInstallment.toLocaleString() }}</strong></span>
              <span v-if="installmentFinalDueDate">ปิดยอด <strong>{{ formatDate(installmentFinalDueDate) }}</strong> (ก่อนเดินทาง {{ installmentLeadDays }} วัน)</span>
            </div>
          </div>

          <!-- Installment Schedule Table -->
          <div v-if="paymentType === 'installment'" class="mt-8">
             <div class="flex items-center justify-between mb-4">
                <h3 class="font-bold text-gray-900 tracking-tight">ตารางการผ่อนชำระ</h3>
                <span class="text-[11px] font-black text-amber-600 bg-amber-100 px-3 py-1 rounded-full uppercase tracking-tighter">ยอดรวมคงเดิม ไม่มีดอกเบี้ย</span>
             </div>
            <div class="overflow-hidden rounded-2xl border border-gray-100">
              <table class="w-full text-sm">
                <thead>
                  <tr class="bg-gray-50">
                    <th class="text-left px-5 py-4 font-bold text-gray-600 uppercase tracking-widest text-[10px]">งวดที่</th>
                    <th class="text-left px-5 py-4 font-bold text-gray-600 uppercase tracking-widest text-[10px]">ครบกำหนด</th>
                    <th class="text-right px-5 py-4 font-bold text-gray-600 uppercase tracking-widest text-[10px]">จำนวนเงิน</th>
                    <th class="text-center px-5 py-4 font-bold text-gray-600 uppercase tracking-widest text-[10px]">สถานะ</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                  <tr v-for="inst in installmentSchedule" :key="inst.no"
                    class="transition-colors"
                    :class="inst.no === 1 ? 'bg-amber-50/20' : 'hover:bg-gray-50'">
                    <td class="px-5 py-4 text-gray-700 font-bold">งวดที่ {{ inst.no }}</td>
                    <td class="px-5 py-4 text-gray-600">{{ formatDate(inst.dueDate) }}</td>
                    <td class="px-5 py-4 text-right font-black text-gray-900 border-r border-gray-50">฿{{ inst.amount.toLocaleString() }}</td>
                    <td class="px-5 py-4 text-center">
                      <span v-if="inst.no === 1"
                        class="text-[10px] font-black px-3 py-1.5 rounded-full bg-teal-600 text-white uppercase tracking-tighter">
                        ชำระงวดแรก
                      </span>
                      <span v-else
                        class="text-[10px] font-black px-3 py-1.5 rounded-full bg-gray-100 text-gray-400 uppercase tracking-tighter">
                        รอชำระ
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- No-refund Warning -->
            <div class="flex gap-4 p-5 bg-red-50/50 border border-red-100 rounded-2xl mt-6">
              <div class="w-10 h-10 rounded-xl bg-red-100 flex items-center justify-center shrink-0">
                <span class="material-symbols-rounded text-red-600 text-[24px]">priority_high</span>
              </div>
              <div class="text-[13px] text-red-700 leading-relaxed">
                <p class="font-black mb-1 text-sm uppercase tracking-tight">สำคัญมาก: เงื่อนไขการยกเลิก</p>
                <p>หากท่านไม่ชำระเงินตามตารางผ่อนชำระที่เลือกไว้ภายในวันครบกำหนด <strong>ลุยเลเขาขอสงวนสิทธิ์ในการยกเลิกทริปและไม่คืนเงินทุกกรณี</strong> เพื่อความมั่นใจในการเดินทางของเพื่อนร่วมทริปท่านอื่น</p>
              </div>
            </div>
          </div>
        </section>

        <!-- ── Payment Method ── -->
        <section class="bg-white rounded-3xl p-8 border border-gray-100">
          <h2 class="text-xl font-extrabold mb-8 text-gray-900 flex items-center gap-2">
            <span class="material-symbols-rounded text-teal-600">account_balance_wallet</span>
            เลือกช่องทางชำระเงิน
          </h2>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-10">
            <button @click="paymentMethod = 'promptpay'"
              class="group flex flex-col items-center justify-center gap-3 p-6 border-2 rounded-3xl transition-all h-full"
              :class="paymentMethod === 'promptpay'
                ? 'border-teal-600 bg-teal-50/30'
                : 'border-gray-50 bg-gray-50/50 hover:bg-gray-100 text-gray-500'">
              <div class="bg-white p-4 rounded-2xl border border-gray-100 group-hover:scale-105 transition-transform">
                <img src="/images/qr_promptpay.webp" alt="พร้อมเพย์" class="h-12 w-auto object-contain" />
              </div>
              <div class="text-center">
                <p class="font-black text-gray-900 tracking-tight">QR PromptPay</p>
                <p class="text-[11px] text-gray-500 font-medium tracking-tight">ชำระผ่าน Mobile Banking ได้ทันที</p>
              </div>
            </button>
            
            <button @click="paymentMethod = 'mobile_banking'"
              class="group flex flex-col items-center justify-center gap-3 p-6 border-2 rounded-3xl transition-all h-full"
              :class="paymentMethod === 'mobile_banking'
                ? 'border-teal-600 bg-teal-50/30'
                : 'border-gray-50 bg-gray-50/50 hover:bg-gray-100 text-gray-500'">
              <div class="bg-white p-4 rounded-2xl border border-gray-100 group-hover:scale-105 transition-transform">
                <img src="/images/pay_bank.webp" alt="โมบายแบงก์กิ้ง" class="h-12 w-auto object-contain" />
              </div>
              <div class="text-center">
                <p class="font-black text-gray-900 tracking-tight">{{ useBeam ? 'เปิดแอปธนาคาร' : 'โอนผ่านบัญชีธนาคาร' }}</p>
                <p class="text-[11px] text-gray-500 font-medium tracking-tight">{{ useBeam ? 'เด้งเข้าแอปธนาคารของคุณโดยตรง' : 'แนบสลิปผ่านทางหน้านี้' }}</p>
              </div>
            </button>
          </div>

          <!-- PromptPay QR -->
          <div v-if="paymentMethod === 'promptpay'" class="flex flex-col items-center gap-5 py-10 bg-gray-50/50 rounded-3xl border border-gray-100">
             <!-- Thai QR Logo Header -->
             <div class="bg-white px-6 py-3 rounded-2xl border border-gray-100 flex items-center justify-center animate-in fade-in zoom-in-95 duration-500">
               <img src="/images/Thai_QR_Payment_Logo-01.jpg" alt="Thai QR Payment" class="h-10 w-auto object-contain" />
             </div>

             <div class="text-center space-y-1">
                <p class="text-base font-bold text-gray-900">เปิดแอปธนาคารแล้วสแกน QR นี้</p>
                <p class="text-xs text-gray-500 px-4">
                  {{ useBeam ? 'จ่ายแล้วระบบจะยืนยันที่นั่งให้อัตโนมัติ ไม่ต้องแนบสลิป' : 'ระบบจะคำนวณยอดชำระเบื้องต้นให้โดยอัตโนมัติ' }}
                </p>
             </div>

            <!-- QR จากเกตเวย์ — เงินเข้าแล้วรู้ทันที ไม่ต้องให้ใครมาตรวจสลิป -->
            <template v-if="useBeam">
              <!-- ธนาคารตอบว่าไม่ผ่าน — บอกให้ชัดแทนที่จะปล่อยให้ QR ใบตายค้างอยู่ -->
              <div v-if="beamFailed" class="w-full max-w-sm px-4">
                <div class="rounded-3xl border border-red-100 bg-red-50/60 p-7 text-center">
                  <span class="material-symbols-rounded text-red-500 text-4xl">credit_card_off</span>
                  <p class="mt-3 text-base font-black text-gray-900">การชำระเงินไม่สำเร็จ</p>
                  <p class="mt-1.5 text-xs text-gray-500 font-medium leading-relaxed">
                    ธนาคารแจ้งว่ารายการนี้ไม่ผ่าน ยังไม่มีการตัดเงิน · สร้างรายการใหม่แล้วลองอีกครั้งได้เลย
                  </p>
                  <button @click="createBeamCharge('QR_PROMPT_PAY')"
                    class="mt-5 px-5 py-2.5 bg-teal-600 text-white text-xs font-black rounded-2xl hover:bg-teal-700 active:scale-95 transition-all">
                    สร้าง QR ใหม่
                  </button>
                </div>
              </div>

              <!--
                จ่ายแล้วแต่ webhook ยังไม่มา — เดิมช่วงนี้หน้าจอค้างอยู่กับ QR ใบเดิม
                จนลูกค้าไม่แน่ใจว่าจ่ายผ่านไหม เอา QR ออกไปเลยระหว่างนี้ กันจ่ายซ้ำ
              -->
              <div v-else-if="beamSettling" class="w-full max-w-sm px-4">
                <PaymentSettlingPanel :seconds="beamSettlingSeconds" :slow="beamSlow">
                  <template #actions>
                    <button @click="resumeBeamWaiting"
                      class="mt-5 text-[11px] font-bold text-gray-400 hover:text-gray-600 underline underline-offset-4">
                      ยังไม่ได้จ่าย · กลับไปสแกน QR
                    </button>
                  </template>
                </PaymentSettlingPanel>
              </div>

              <template v-else>
                <div class="relative group">
                  <div class="relative p-2 bg-white rounded-3xl border border-teal-100 overflow-hidden min-h-[280px] min-w-[280px] flex items-center justify-center">
                    <img v-if="beamQrSrc && !beamExpired" :src="beamQrSrc" alt="QR พร้อมเพย์"
                      class="block rounded-2xl w-full max-w-[280px] h-auto mx-auto" />

                    <div v-if="beamLoading" class="absolute inset-0 flex items-center justify-center bg-white/90 rounded-3xl">
                      <div class="w-10 h-10 rounded-full border-4 border-teal-100 border-t-teal-600 animate-spin"></div>
                    </div>

                    <!-- QR หมดอายุก่อนที่นั่งจะถูกคืน ลูกค้าจึงยังกดออกใบใหม่ได้ -->
                    <div v-else-if="beamExpired" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-white/95 rounded-3xl px-6 text-center">
                      <span class="material-symbols-rounded text-4xl text-gray-300">qr_code_2</span>
                      <p class="text-sm font-bold text-gray-900">QR หมดอายุแล้ว</p>
                      <button @click="createBeamCharge('QR_PROMPT_PAY')"
                        class="px-5 py-2.5 bg-teal-600 text-white text-xs font-black rounded-2xl hover:bg-teal-700 active:scale-95 transition-all">
                        สร้าง QR ใหม่
                      </button>
                    </div>
                  </div>
                </div>

                <div v-if="beamPayment && !beamExpired" class="flex items-center gap-2 px-4 py-2 rounded-full bg-white border border-teal-100">
                  <div class="w-2 h-2 rounded-full bg-teal-500 animate-pulse"></div>
                  <p class="text-xs font-bold text-gray-700">
                    กำลังรอการชำระเงิน · QR หมดอายุใน <span class="text-teal-600 tabular-nums">{{ beamCountdownText }}</span>
                  </p>
                </div>

                <!--
                  ขั้นตอนอยู่ตรงนี้ตั้งแต่ยังไม่จ่าย ไม่ต้องรอให้ใครกดปุ่มก่อน — แถวที่กำลัง
                  ทำงานอยู่หมุนตลอด หน้าจอจึงไม่มีวินาทีไหนที่ดูเหมือนค้าง และพอลูกค้าออกไป
                  จ่ายแล้วกลับเข้ามา การ์ดนี้จะเลื่อนไปขั้นถัดไปเอง
                -->
                <div v-if="beamPayment && !beamExpired" class="w-full max-w-sm px-4">
                  <PaymentSettlingPanel stage="waiting" />
                </div>
              </template>

              <p v-if="beamError" class="text-xs font-bold text-red-600 px-6 text-center">{{ beamError }}</p>
            </template>

            <div v-else class="relative group">
               <div class="absolute -inset-4 bg-teal-600/5 rounded-[2.5rem] blur-xl opacity-0 group-hover:opacity-100 transition-opacity"></div>
               <div class="relative p-2 bg-white rounded-3xl border border-teal-100 overflow-hidden">
                <canvas ref="qrCanvas" class="block rounded-2xl w-full max-w-[320px] h-auto mx-auto"></canvas>
                <div v-if="!qrGenerated" class="absolute inset-0 flex items-center justify-center bg-white/80 rounded-3xl">
                  <div class="w-10 h-10 rounded-full border-4 border-teal-100 border-t-teal-600 animate-spin"></div>
                </div>
              </div>
            </div>

            <div class="flex flex-col items-center gap-4 w-full px-6 mt-6">
                <!-- Save QR Button moved up -->
                <div class="flex items-center gap-3">
                  <button v-if="qrGenerated && !useBeam" @click="saveQR"
                    class="flex items-center gap-2.5 px-6 py-3 bg-teal-600 text-white text-sm font-black rounded-2xl hover:bg-teal-700 active:scale-95 transition-all">
                    <span class="material-symbols-rounded text-[18px]">download</span> บันทึก QR Code
                  </button>
                </div>

                <!-- Payment Details -->
                <div class="flex items-center justify-between w-full max-w-xs bg-white p-3 rounded-2xl border border-gray-100">
                   <div class="flex flex-col">
                      <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest leading-none">ยอดเงินที่ต้องชำระ</span>
                      <span class="text-lg font-black text-teal-600">฿{{ currentPayAmount.toLocaleString() }}</span>
                   </div>
                    <button @click="copyAmount" class="flex items-center gap-1.5 px-3 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-bold rounded-xl transition-colors active:scale-95">
                      <span class="material-symbols-rounded text-base">content_copy</span>
                      คัดลอกยอด
                    </button>
                </div>
            </div>
             
             <div class="flex items-center gap-2 py-2 px-4 rounded-full bg-white border border-gray-100">
                <span class="material-symbols-rounded text-teal-600 text-sm" style="font-variation-settings:'FILL' 1">verified_user</span>
                <p class="text-[11px] text-gray-500 font-bold">e-Wallet: <span class="text-gray-900">004-99923936-2071</span></p>
             </div>
          </div>

          <!-- โหมดเกตเวย์: เด้งเข้าแอปธนาคารโดยตรง ไม่ต้องจดเลขบัญชีหรือแนบสลิป -->
          <div v-else-if="useBeam" class="bg-teal-50/50 rounded-3xl p-6 space-y-5 border border-teal-100">
            <p class="text-sm font-black text-teal-900 flex items-center gap-2">
              <span class="material-symbols-rounded text-teal-600 text-[20px]">smartphone</span>
              เลือกแอปธนาคารของคุณ
            </p>
            <p class="text-xs text-teal-800/80 font-medium leading-relaxed">
              ระบบจะพาไปที่แอปธนาคารพร้อมยอด ฿{{ currentPayAmount.toLocaleString() }} จ่ายเสร็จแล้วกลับมาหน้านี้ได้เลย ที่นั่งจะถูกยืนยันให้อัตโนมัติ
            </p>

            <div class="grid grid-cols-2 gap-3">
              <button v-for="app in bankApps" :key="app.type"
                @click="createBeamCharge(app.type)"
                :disabled="beamLoading"
                class="flex flex-col items-start gap-1 p-4 bg-white rounded-2xl border border-teal-100/50 hover:border-teal-600 transition-colors text-left active:scale-95 disabled:opacity-50">
                <span class="text-sm font-black text-gray-900">{{ app.label }}</span>
                <span class="text-[11px] text-gray-500 font-medium">{{ app.bank }}</span>
              </button>
            </div>

            <p v-if="!bankApps.length" class="text-xs font-bold text-gray-500">
              ยังไม่เปิดรับการจ่ายผ่านแอปธนาคาร กรุณาใช้ QR PromptPay
            </p>
            <p v-if="beamError" class="text-xs font-bold text-red-600">{{ beamError }}</p>
          </div>

          <!-- Bank Transfer info -->
          <div v-else class="bg-teal-50/50 rounded-3xl p-6 space-y-5 border border-teal-100">
            <p class="text-sm font-black text-teal-900 flex items-center gap-2">
              <span class="material-symbols-rounded text-teal-600 text-[20px]">account_balance</span>
              ข้อมูลบัญชีธนาคาร
            </p>
            <div class="space-y-3">
              <div class="flex items-center justify-between p-4 bg-white rounded-2xl border border-teal-100/50">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-teal-600 flex items-center justify-center shrink-0">
                      <span class="material-symbols-rounded text-white text-xl">account_balance</span>
                    </div>
                    <div>
                      <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest leading-none">ธนาคาร</p>
                      <p class="text-sm font-bold text-gray-900">กสิกรไทย (KBANK)</p>
                    </div>
                  </div>
              </div>
              <div class="flex items-center justify-between p-4 bg-white rounded-2xl border border-teal-100/50 relative group">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gray-50 flex items-center justify-center shrink-0">
                      <span class="material-symbols-rounded text-teal-600 text-xl">person</span>
                    </div>
                    <div>
                      <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest leading-none">ชื่อบัญชี</p>
                      <p class="text-sm font-bold text-gray-900">ลุยเลเขา</p>
                    </div>
                  </div>
              </div>
               <div class="flex items-center justify-between p-4 bg-white rounded-2xl border border-teal-600/30 relative group">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-teal-50 flex items-center justify-center shrink-0">
                      <span class="material-symbols-rounded text-teal-600 text-xl">numbers</span>
                    </div>
                    <div>
                      <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest leading-none">เลขที่บัญชี</p>
                      <p class="text-lg font-black text-gray-900 tracking-wider">230-1-39095-8</p>
                    </div>
                  </div>
                  <button @click="copyAccount" class="p-2.5 rounded-xl bg-teal-50 text-teal-600 hover:bg-teal-100 transition-colors active:scale-90">
                    <span class="material-symbols-rounded text-xl">content_copy</span>
                  </button>
              </div>
            </div>
            
            <div class="bg-amber-50 p-4 rounded-2xl border border-amber-100 flex items-start gap-3">
               <span class="material-symbols-rounded text-amber-500 text-xl font-bold">info</span>
               <p class="text-xs text-amber-800 font-medium leading-relaxed">
                  กรุณาโอนยอด <strong class="text-amber-900">฿{{ currentPayAmount.toLocaleString() }}</strong> ให้ครบถ้วน แล้วอัปโหลดสลิป ระบบจะตรวจยอดและยืนยันให้อัตโนมัติ
               </p>
            </div>
          </div>

          <!-- Divider -->
          <div v-if="!useBeam" class="flex items-center gap-4 my-10">
            <div class="flex-1 h-[2px] bg-gray-50"></div>
            <span class="text-[11px] text-gray-400 font-black uppercase tracking-[0.2em]">หลักฐานการโอนเงิน</span>
            <div class="flex-1 h-[2px] bg-gray-50"></div>
          </div>

          <!-- Slip Upload — โหมดเกตเวย์ไม่ต้องใช้ เงินเข้าแล้วระบบรู้เอง -->
          <div v-if="!useBeam" class="space-y-6">
            <div class="flex items-center justify-between">
               <label class="block text-sm font-black text-gray-900">อัปโหลดสลิปการโอนเงินที่นี่ <span class="text-red-500 font-normal">*</span></label>
               <span v-if="slipFile" class="text-xs font-bold text-teal-600 flex items-center gap-1">
                 <span class="material-symbols-rounded text-base">check_circle</span>
                 เลือกไฟล์แล้ว
               </span>
            </div>

            <div 
              @click="slipInputRef?.click()"
              @dragover.prevent="isDragging = true"
              @dragleave.prevent="isDragging = false"
              @drop.prevent="handleDrop"
              class="group relative flex flex-col items-center justify-center gap-4 border-3 border-dashed rounded-[2.5rem] py-12 px-6 cursor-pointer transition-all duration-500"
              :class="[
                isDragging ? 'border-teal-600 bg-teal-50 scale-[0.99]' : 'border-gray-200 bg-gray-50/50 hover:border-teal-400 hover:bg-teal-50/20',
                slipPreview ? 'border-none p-0 overflow-hidden bg-transparent' : ''
              ]">
              
              <template v-if="!slipPreview">
                <div class="w-20 h-20 rounded-[2rem] bg-white text-teal-500 flex items-center justify-center border border-gray-100 group-hover:scale-110 transition-transform duration-500">
                  <span class="material-symbols-rounded text-4xl">cloud_upload</span>
                </div>
                <div class="text-center space-y-1">
                  <p class="text-base font-black text-gray-900">ลากไฟล์มาวาง หรือคลิกเพื่ออัปโหลด</p>
                  <p class="text-[11px] text-gray-400 font-bold uppercase tracking-widest">JPG, PNG, PDF ขนาดไม่เกิน 5MB</p>
                </div>
              </template>

              <template v-else>
                 <div class="relative w-full max-h-[400px] group/preview">
                    <img :src="slipPreview" alt="slip" class="w-full h-full object-contain rounded-[2rem] bg-gray-100" />
                    <div class="absolute inset-0 bg-black/40 opacity-0 group-hover/preview:opacity-100 transition-opacity rounded-[2rem] flex items-center justify-center">
                       <p class="text-white font-bold text-sm bg-white/20 backdrop-blur-md px-4 py-2 rounded-full border border-white/30">คลิกเพื่อเปลี่ยนรูป</p>
                    </div>
                    <button @click.stop="removeSlip"
                      class="absolute top-4 right-4 w-10 h-10 bg-white/90 backdrop-blur-md text-red-600 rounded-full flex items-center justify-center hover:bg-red-600 hover:text-white transition-all z-20">
                      <span class="material-symbols-rounded text-[22px]">delete</span>
                    </button>
                    <!-- Success indicator -->
                    <div class="absolute bottom-4 left-4 bg-teal-600 text-white px-4 py-2 rounded-full text-xs font-black flex items-center gap-2">
                       <span class="material-symbols-rounded text-[18px]">check_circle</span>
                       อัปโหลดสำเร็จ
                    </div>
                 </div>
              </template>
            </div>
            
            <input ref="slipInputRef" type="file" accept="image/*" required class="hidden" @change="onSlipChange" />

            <!-- Auto slip check status — ตรวจยอดจากสลิปให้อัตโนมัติ ไม่ต้องกรอกวัน/เวลาเอง -->
            <div v-if="slipFile" class="rounded-3xl p-5 border flex items-start gap-3 transition-colors"
              :class="scanningSlip
                ? 'bg-gray-50/50 border-gray-100'
                : slipAmountMatched
                  ? 'bg-teal-50 border-teal-100'
                  : 'bg-amber-50 border-amber-100'">
              <template v-if="scanningSlip">
                <span class="w-6 h-6 border-2 border-teal-600/30 border-t-teal-600 rounded-full animate-spin shrink-0 mt-0.5"></span>
                <div>
                  <p class="text-sm font-black text-gray-900">กำลังตรวจสอบสลิปและยอดเงิน...</p>
                  <p class="text-xs text-gray-500 font-medium mt-0.5">ระบบกำลังอ่านสลิปให้อัตโนมัติ รอสักครู่</p>
                </div>
              </template>
              <template v-else-if="slipAmountMatched">
                <span class="material-symbols-rounded text-teal-600 shrink-0" style="font-variation-settings:'FILL' 1">verified</span>
                <div>
                  <p class="text-sm font-black text-teal-900">ยอดเงินถูกต้อง — กำลังยืนยันอัตโนมัติ</p>
                  <p class="text-xs text-teal-700 font-medium mt-0.5">ไม่ต้องกดยืนยัน ระบบดำเนินการให้เรียบร้อย</p>
                </div>
              </template>
              <template v-else-if="slipChecked">
                <span class="material-symbols-rounded text-amber-500 shrink-0" style="font-variation-settings:'FILL' 1">info</span>
                <div>
                  <p class="text-sm font-black text-amber-900">
                    <template v-if="slipDetectedAmount !== null">ยอดในสลิป (฿{{ slipDetectedAmount.toLocaleString() }}) ไม่ตรงกับยอดที่ต้องชำระ</template>
                    <template v-else>ตรวจยอดจากสลิปอัตโนมัติไม่สำเร็จ</template>
                  </p>
                  <p class="text-xs text-amber-700 font-medium mt-0.5">กดปุ่มยืนยันด้านล่างเพื่อส่งให้เจ้าหน้าที่ตรวจสอบและยืนยันการจอง</p>
                </div>
              </template>
            </div>
          </div>
        </section>

        <!-- Trust Badges & Support -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
           <div class="flex items-center gap-4 p-6 bg-white rounded-3xl border border-gray-100">
             <div class="w-12 h-12 rounded-2xl bg-teal-50 text-teal-600 flex items-center justify-center shrink-0">
               <span class="material-symbols-rounded text-2xl" style="font-variation-settings:'FILL' 1">verified</span>
             </div>
             <div>
                <p class="text-sm font-bold text-gray-900">ความปลอดภัย 100%</p>
                <p class="text-xs text-gray-500">ข้อมูลของคุณได้รับการเข้ารหัส SSL Encryption</p>
             </div>
           </div>
           <div class="flex items-center gap-4 p-6 bg-white rounded-3xl border border-gray-100">
             <div class="w-12 h-12 rounded-2xl bg-teal-50 text-teal-600 flex items-center justify-center shrink-0">
               <span class="material-symbols-rounded text-2xl" style="font-variation-settings:'FILL' 1">support_agent</span>
             </div>
             <div>
                <p class="text-sm font-bold text-gray-900">ทีมงานพร้อมช่วยเหลือ</p>
                <p class="text-xs text-gray-500">สอบถามโทร 062-612-6006 (8:00 - 20:00)</p>
             </div>
           </div>
        </div>

      </div>

      <!-- RIGHT: Booking Summary Card -->
      <aside class="lg:col-span-4 sticky top-24 pb-20">
        <div class="bg-white rounded-[2.5rem] overflow-hidden border border-gray-100 transition-all duration-300">

          <!-- Trip Premium Header -->
          <div class="h-56 relative overflow-hidden bg-gray-100">
            <img v-if="booking.schedule?.trip?.thumbnail_image || booking.schedule?.trip?.cover_image"
              :src="booking.schedule.trip.thumbnail_image || booking.schedule.trip.cover_image"
              :alt="booking.schedule?.trip?.title"
              class="w-full h-full object-cover transition-transform duration-700 hover:scale-110" />
            
            <div class="absolute inset-0 bg-gradient-to-t from-gray-900 via-gray-900/20 to-transparent"></div>
            
            <div class="absolute top-4 left-4">
               <span class="bg-white/20 backdrop-blur-md text-white text-[10px] font-black px-3 py-1.5 rounded-full uppercase tracking-widest border border-white/30">
                #{{ booking.booking_ref }}
              </span>
            </div>

            <div class="absolute bottom-5 left-6 right-6">
               <p class="text-white text-lg font-black leading-tight mb-2">{{ booking.schedule?.trip?.title }}</p>
               <div class="flex items-center gap-4 text-white/90 text-xs font-bold">
                  <div class="flex items-center gap-1.5 bg-white/10 backdrop-blur-sm px-2.5 py-1.5 rounded-xl border border-white/10">
                    <span class="material-symbols-rounded text-[14px]">calendar_today</span>
                    {{ formatDate(booking.schedule?.departure_date) }}
                  </div>
                  <div class="flex items-center gap-1.5 bg-white/10 backdrop-blur-sm px-2.5 py-1.5 rounded-xl border border-white/10">
                    <span class="material-symbols-rounded text-[14px]">group</span>
                    {{ booking.passengers?.length || 0 }} ท่าน
                  </div>
               </div>
            </div>
          </div>

          <div class="p-8">
            <h2 class="text-xs font-black text-gray-400 uppercase tracking-[0.2em] mb-6">รายละเอียดการจอง</h2>

            <div class="space-y-4 mb-8">
               <div v-if="booking.seats?.length" class="flex items-center gap-3 p-4 bg-gray-50 rounded-2xl border border-gray-100 animate-in fade-in slide-in-from-bottom-2">
                 <div class="w-10 h-10 rounded-xl bg-white text-teal-600 flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-xl">airline_seat_recline_extra</span>
                 </div>
                 <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest leading-none">หมายเลขที่นั่ง</p>
                    <p class="text-sm font-bold text-gray-900">{{ booking.seats.map(s => s.seat_id).join(', ') }}</p>
                 </div>
               </div>
               
               <div class="flex items-center gap-3 p-4 bg-gray-50 rounded-2xl border border-gray-100 animate-in fade-in slide-in-from-bottom-2 duration-500">
                 <div class="w-10 h-10 rounded-xl bg-white text-amber-600 flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-xl">location_on</span>
                 </div>
                 <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest leading-none">จุดรับเดินทาง</p>
                    <p class="text-sm font-black text-gray-900 leading-tight">
                      {{ booking.pickup_point?.pickup_location || 'ยังไม่ได้ระบุจุดรับ' }}
                    </p>
                    <p v-if="booking.pickup_point?.region_label || booking.pickup_region" class="text-[11px] font-bold text-amber-600 mt-0.5">
                      {{ booking.pickup_point?.region_label || formatRegion(booking.pickup_region) }}
                    </p>
                 </div>
               </div>

               <div v-if="booking.selected_addons?.length" class="p-4 bg-amber-50/70 rounded-2xl border border-amber-100 animate-in fade-in slide-in-from-bottom-2 duration-500">
                 <div class="flex items-center gap-2 mb-3">
                   <span class="material-symbols-rounded text-amber-600 text-xl">add_task</span>
                   <p class="text-[10px] font-black text-amber-700 uppercase tracking-widest leading-none">ตัวเลือกเสริม</p>
                 </div>
                 <div class="space-y-2">
                   <div v-for="(addon, idx) in booking.selected_addons" :key="idx" class="flex justify-between gap-3 text-sm">
                     <span class="font-bold text-gray-800">{{ addon.name }}</span>
                     <span class="font-black text-amber-700">+฿{{ Number(addon.total_price || 0).toLocaleString() }}</span>
                   </div>
                 </div>
               </div>

               <div v-if="booking.selected_rentals?.length" class="p-4 bg-sky-50/70 rounded-2xl border border-sky-100 animate-in fade-in slide-in-from-bottom-2 duration-500">
                 <div class="flex items-center gap-2 mb-3">
                   <span class="material-symbols-rounded text-sky-600 text-xl">backpack</span>
                   <p class="text-[10px] font-black text-sky-700 uppercase tracking-widest leading-none">อุปกรณ์เช่า</p>
                 </div>
                 <div class="space-y-2">
                   <div v-for="(rental, idx) in booking.selected_rentals" :key="idx" class="flex justify-between gap-3 text-sm">
                     <span class="font-bold text-gray-800">
                       {{ rental.name }}
                       <span v-if="Number(rental.quantity) > 1" class="text-sky-600">×{{ rental.quantity }}</span>
                     </span>
                     <span class="font-black text-sky-700">+฿{{ Number(rental.total_price || 0).toLocaleString() }}</span>
                   </div>
                 </div>
               </div>
            </div>

            <!-- Price Summary -->
            <div class="space-y-3 pt-6 border-t border-gray-100 mb-8">
               <div class="flex justify-between items-center text-sm">
                  <span class="font-bold text-gray-500">ยอดรวมทั้งหมด</span>
                  <span class="font-bold text-gray-900">฿{{ Number(booking.total_amount).toLocaleString() }}</span>
               </div>
               
               <template v-if="paymentType === 'installment'">
                 <div class="flex justify-between items-end bg-amber-50 rounded-2xl p-4 border border-amber-100">
                    <span class="text-xs font-bold text-amber-700">ชำระงวดแรกตอนนี้</span>
                    <div class="text-right">
                       <span class="text-2xl font-black text-amber-600 leading-none">฿{{ perInstallment.toLocaleString() }}</span>
                       <p class="text-[10px] font-bold text-amber-500 leading-none mt-1">จากทั้งหมด {{ selectedInstallmentCount }} งวด</p>
                    </div>
                 </div>
               </template>

               <template v-else-if="paymentType === 'deposit'">
                 <div class="flex justify-between items-end bg-teal-50 rounded-2xl p-4 border border-teal-200">
                    <span class="text-xs font-bold text-teal-700">ชำระมัดจำตอนนี้</span>
                    <div class="text-right">
                       <span class="text-2xl font-black text-teal-700 leading-none">฿{{ depositAmount.toLocaleString() }}</span>
                       <p class="text-[10px] font-bold text-teal-600 leading-none mt-1">ส่วนที่เหลือ ฿{{ balanceAmount.toLocaleString() }}</p>
                    </div>
                 </div>
               </template>

               <template v-else>
                 <div class="flex justify-between items-end bg-teal-50 rounded-2xl p-5 border border-teal-100">
                    <span class="text-sm font-black text-teal-900 uppercase tracking-tight">ยอดชำระสุทธิ</span>
                    <span class="text-3xl font-black text-teal-600 leading-none">฿{{ Number(booking.total_amount).toLocaleString() }}</span>
                 </div>
               </template>
            </div>

            <!-- โหมดเกตเวย์: ไม่มีปุ่ม "ยืนยัน" เพราะการจ่ายจบที่แอปธนาคาร ไม่ใช่ที่นี่ -->
            <div v-if="useBeam" class="space-y-4">
              <div class="rounded-2xl p-5 border flex items-start gap-3"
                :class="beamSettling ? 'bg-white border-teal-200' : 'bg-teal-50 border-teal-100'">
                <div v-if="beamSettling"
                  class="w-5 h-5 rounded-full border-[3px] border-teal-100 border-t-teal-600 animate-spin mt-0.5 shrink-0"></div>
                <div v-else class="w-2.5 h-2.5 rounded-full bg-teal-500 animate-pulse mt-1.5 shrink-0"></div>
                <div class="space-y-1">
                  <p class="text-sm font-black text-teal-900">
                    {{ beamSettling ? 'กำลังตรวจสอบการชำระเงิน' : 'สแกน QR แล้วรอสักครู่' }}
                  </p>
                  <p class="text-xs text-teal-800/80 font-medium leading-relaxed">
                    {{ beamSettling
                      ? 'ระบบกำลังรอผลจากธนาคาร อย่าปิดหน้านี้และไม่ต้องจ่ายซ้ำ ที่นั่งจะถูกยืนยันให้เองทันทีที่เงินเข้า'
                      : 'ระบบจะยืนยันที่นั่งให้อัตโนมัติทันทีที่เงินเข้า ไม่ต้องแนบสลิปและไม่ต้องรอเจ้าหน้าที่ตรวจ' }}
                  </p>
                </div>
              </div>
            </div>

            <!-- Main CTA Button -->
            <div v-else class="space-y-4">
              <button @click="processPayment"
                :disabled="paying || scanningSlip || !slipFile"
                class="group w-full py-5 rounded-2xl font-black text-base flex flex-col items-center justify-center gap-1 transition-all duration-500 overflow-hidden relative disabled: disabled:bg-gray-100 disabled:text-gray-400 disabled:cursor-not-allowed"
                :class="[
                  paymentType === 'installment'
                    ? 'bg-amber-600 text-white hover:bg-amber-700'
                    : paymentType === 'deposit'
                      ? 'bg-teal-600 text-white hover:bg-teal-700'
                      : 'bg-emerald-600 text-white hover:bg-emerald-700'
                ]">
                <!-- Loading State overlay -->
                <div v-if="paying || scanningSlip" class="absolute inset-0 bg-inherit flex items-center justify-center gap-3 z-10 text-sm">
                   <div class="w-6 h-6 rounded-full border-2 border-white/30 border-t-white animate-spin"></div>
                   <span v-if="scanningSlip" class="text-white/90">กำลังตรวจสอบสลิป...</span>
                </div>

                <div class="flex items-center gap-2.5 transition-transform group-hover:scale-105" :class="paying || scanningSlip ? 'opacity-0' : 'opacity-100'">
                  <span class="material-symbols-rounded text-xl" style="font-variation-settings:'FILL' 1">verified_user</span>
                  <span>ยืนยันและส่งหลักฐานการชำระเงิน</span>
                </div>
                <div class="text-[10px] opacity-70 tracking-widest uppercase font-bold" :class="paying || scanningSlip ? 'opacity-0' : 'opacity-70'">
                   เข้ารหัส SSL ที่ปลอดภัย
                </div>
              </button>
              
              <button v-if="!slipFile" @click="slipInputRef?.click()" class="w-full text-center text-xs font-bold text-amber-600 hover:text-amber-700 underline underline-offset-4 animate-bounce">
                 ⚠ กรุณาอัปโหลดสลิปเพื่อดำเนินการต่อ
              </button>
            </div>

            <!-- Error message -->
            <Transition name="fade">
              <div v-if="paymentError" class="mt-6 p-4 rounded-2xl bg-red-50 border border-red-100 flex items-start gap-3">
                <span class="material-symbols-rounded text-red-500 shrink-0">error</span>
                <p class="text-xs font-bold text-red-600">{{ paymentError }}</p>
              </div>
            </Transition>

            <!-- Verification promise -->
            <div class="mt-8 pt-6 border-t border-gray-50">
               <div class="flex items-center gap-3">
                  <div class="w-8 h-8 rounded-full bg-teal-50 flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-teal-600 text-base" style="font-variation-settings:'FILL' 1">{{ useBeam ? 'bolt' : 'av_timer' }}</span>
                  </div>
                  <p class="text-xs text-gray-500 font-medium">
                    {{ useBeam
                      ? 'ระบบยืนยันการจองให้อัตโนมัติภายในไม่กี่วินาทีหลังเงินเข้า'
                      : 'เจ้าหน้าที่จะตรวจสอบยอดโอนและยืนยันการจอง ภายใน 10-15 นาที' }}
                  </p>
               </div>
            </div>
          </div>
        </div>

        <!-- Sticky Mobile Button (Only visible on mobile via class) -->
        <div class="lg:hidden fixed bottom-0 left-0 right-0 p-4 bg-white/80 backdrop-blur-xl border-t border-gray-100 z-[100] translate-y-0 transition-transform duration-500"
          :class="!loading && booking ? 'translate-y-0' : 'translate-y-full'">
            <!-- โหมดเกตเวย์ไม่มีอะไรให้กดยืนยัน — บอกสถานะแทน -->
            <div v-if="useBeam" class="w-full py-4 rounded-2xl bg-teal-50 border border-teal-100 flex items-center justify-center gap-2 text-sm">
              <template v-if="beamSettling">
                <div class="w-4 h-4 rounded-full border-2 border-teal-200 border-t-teal-600 animate-spin"></div>
                <span class="font-black text-teal-900">กำลังตรวจสอบการชำระเงิน</span>
                <span class="text-xs font-bold text-teal-600 tabular-nums">{{ beamSettlingSeconds }}s</span>
              </template>
              <template v-else>
                <div class="w-2 h-2 rounded-full bg-teal-500 animate-pulse"></div>
                <span class="font-black text-teal-900">รอชำระ ฿{{ currentPayAmount.toLocaleString() }}</span>
                <span v-if="beamPayment && !beamExpired" class="text-xs font-bold text-teal-600 tabular-nums">{{ beamCountdownText }}</span>
              </template>
            </div>

            <button v-else @click="processPayment"
              :disabled="paying || scanningSlip || !slipFile"
              class="w-full py-4 rounded-2xl bg-emerald-600 text-white font-black flex items-center justify-center gap-2 active:scale-95 transition-all text-sm disabled:bg-gray-100 disabled:text-gray-400">
              <template v-if="!paying && !scanningSlip">
                <span>ยืนยันการชำระ ฿{{ currentPayAmount.toLocaleString() }}</span>
                <span class="material-symbols-rounded text-lg">arrow_forward</span>
              </template>
              <div v-else class="w-5 h-5 rounded-full border-2 border-white/30 border-t-white animate-spin"></div>
            </button>
        </div>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import QRCode from 'qrcode';
import { useRoute, useRouter } from 'vue-router';
import { useBookingStore } from '../stores/booking';
import { useSeatsStore } from '../stores/seats';
import CountdownTimer from '../components/CountdownTimer.vue';
import PaymentSettlingPanel from '../components/PaymentSettlingPanel.vue';
import { useSwal } from '../lib/swal';
import { addPaymentInfo } from '../lib/analytics';
import { useBeamCharge } from '../composables/useBeamCharge';

const route = useRoute();
const router = useRouter();
const bookingStore = useBookingStore();
const seatsStore = useSeatsStore();
const swal = useSwal();

// เวลาชำระเงินไม่ใช่เวลาล็อกที่นั่ง และไม่ได้ยาวขึ้นตามจำนวนคน — ExpirePendingBookingsJob
// ยกเลิกการจองที่ค้างเกิน Booking::PENDING_TTL_MINUTES นับจาก created_at ทุกนาที เท่ากันหมด
// หน้านี้จึงต้องเดินตาม expires_at ที่ API ส่งมา ไม่ตั้งเวลาของตัวเอง (สูตรเดิม 10 นาที
// +2 นาที/คน ทำให้ผู้จองหลายคนเห็นนาฬิกาเดินต่ออีกหลายนาทีทั้งที่ที่นั่งถูกคืนไปแล้ว
// และ QR ของเกตเวย์ก็หมดอายุไปก่อนหน้านั้นด้วยซ้ำ)
const PENDING_TTL_SECONDS = 10 * 60; // fallback เท่านั้น — ต้องตรงกับ Booking::PENDING_TTL_MINUTES

const paymentDeadline = computed(() => {
  const b = booking.value;
  if (!b || b.status !== 'pending') return null;
  // ส่งสลิปแล้ว = ถือที่นั่งไว้รอแอดมินตรวจ ไม่มีเส้นตาย (หลังบ้านก็ข้ามรายการพวกนี้)
  if (b.slip_ocr_status) return null;

  const serverExpiry = b.expires_at ? new Date(b.expires_at) : null;
  if (serverExpiry && !Number.isNaN(serverExpiry.getTime())) return serverExpiry;

  const createdAtMs = b.created_at ? new Date(b.created_at).getTime() : Date.now();
  const baseTimeMs = Number.isFinite(createdAtMs) ? createdAtMs : Date.now();

  return new Date(baseTimeMs + PENDING_TTL_SECONDS * 1000);
});

/** ความยาวเต็มของหน้าต่างชำระเงิน (created_at → เส้นตาย) ใช้กับแถบความคืบหน้าและข้อความ */
const paymentWindowSeconds = computed(() => {
  const deadline = paymentDeadline.value;
  const createdAtMs = booking.value?.created_at ? new Date(booking.value.created_at).getTime() : NaN;
  if (!deadline || !Number.isFinite(createdAtMs)) return PENDING_TTL_SECONDS;

  const seconds = Math.round((deadline.getTime() - createdAtMs) / 1000);

  return seconds > 0 ? seconds : PENDING_TTL_SECONDS;
});

const paymentWindowMinutes = computed(() => Math.max(1, Math.round(paymentWindowSeconds.value / 60)));

const booking = ref(null);
const loading = ref(true);
// จริงระหว่างที่ onMounted กำลังกู้รูปแบบการชำระของการจองนี้กลับมา — กัน watcher
// ที่คอยออก QR ใหม่ทุกครั้งที่ยอดเปลี่ยน ไม่ให้เข้าใจผิดว่าลูกค้าเพิ่งกดเปลี่ยนเอง
let restoringPlan = false;
const paying = ref(false);
const autoCancelling = ref(false);
const paymentError = ref('');
const paymentMethod = ref('promptpay');
const paymentType = ref('full');

// PromptPay QR
const qrCanvas = ref(null);
const qrGenerated = ref(false);

// Slip upload
const slipFile = ref(null);
const slipPreview = ref(null);
const slipInputRef = ref(null);
const isDragging = ref(false);
const scanningSlip = ref(false);
// ผลตรวจสลิปอัตโนมัติ: ตรวจแล้วหรือยัง และยอดเงินตรงไหม
const slipChecked = ref(false);
const slipAmountMatched = ref(false);
// เผื่อลูกค้าโอนไม่ครบ — เก็บยอดที่ OCR อ่านได้ไว้บอก
const slipDetectedAmount = ref(null);

function handleDrop(e) {
  isDragging.value = false;
  const file = e.dataTransfer.files[0];
  if (file && file.type.startsWith('image/')) {
    setFile(file);
  }
}

function setFile(file) {
  if (!file) return;
  slipFile.value = file;
  const reader = new FileReader();
  reader.onload = (ev) => { slipPreview.value = ev.target.result; };
  reader.readAsDataURL(file);
  scanSlipOcr(file);
}

// อ่านสลิปด้วย OCR: เก็บวัน/เวลาโอนไว้เงียบ ๆ (ไม่ให้ลูกค้ากรอกเอง) และตรวจยอดเงิน
// ถ้ายอดตรง (สถานะสำเร็จ + ต่างไม่เกิน 2 บาท เท่ากับที่ backend ใช้) → ยืนยันให้อัตโนมัติ
// เลยโดยไม่ต้องกดปุ่ม ถ้าอ่านไม่ได้หรือยอดไม่ตรง ก็ปล่อยให้กดปุ่มยืนยันเองตามเดิม
const AMOUNT_TOLERANCE = 2;
async function scanSlipOcr(file) {
  if (!file || !file.type?.startsWith('image/')) return;
  scanningSlip.value = true;
  slipChecked.value = false;
  slipAmountMatched.value = false;
  slipDetectedAmount.value = null;
  let matched = false;
  try {
    const res = await bookingStore.scanSlip((() => {
      const fd = new FormData();
      fd.append('slip_image', file);
      return fd;
    })());
    const data = res?.data || {};
    if (data.date) transferDate.value = data.date;
    if (data.time) transferTime.value = data.time;
    const amount = Number(data.amount);
    if (Number.isFinite(amount)) slipDetectedAmount.value = amount;
    matched = data.status === 'success'
      && Number.isFinite(amount)
      && Math.abs(amount - currentPayAmount.value) <= AMOUNT_TOLERANCE;
    slipAmountMatched.value = matched;
  } catch (e) {
    // เงียบ — ตกไปใช้การกดปุ่มยืนยันเอง แล้วเจ้าหน้าที่ตรวจสอบ
  } finally {
    slipChecked.value = true;
    scanningSlip.value = false;
  }

  // ยอดตรง → ยืนยันอัตโนมัติทันที (backend ยืนยันการจองให้อยู่แล้ว)
  if (matched) {
    await processPayment();
  }
}

function copyAmount() {
  const amount = currentPayAmount.value;
  navigator.clipboard.writeText(amount.toString());
  swal.success('คัดลอกยอดเงินแล้ว', `฿${amount.toLocaleString()}`);
}

function copyAccount() {
  navigator.clipboard.writeText('004999239362071');
  swal.success('คัดลอกเลข e-Wallet แล้ว', '004-99923936-2071');
}

// Transfer datetime
const transferDate = ref('');
const transferTime = ref('');

// ── ยอดที่ต้องชำระ — มาจากหลังบ้านที่เดียว ────────────────────
// เว็บกับแอปเคยคำนวณมัดจำ/งวดกันเอง สูตรไม่ตรงกับหลังบ้าน (มัดจำแบบยอดคงที่คิด
// ต่อคน และมีส่วนลดมัดจำตามระดับสมาชิก) ลูกค้าจึงโอนมาไม่เท่ากันจนสลิปถูกกันไว้
// ตรวจ ตอนนี้อ่านจาก booking.payment_options ตรง ๆ
const quote = computed(() => booking.value?.payment_options || null);

// ── Beam Checkout ────────────────────────────────────────────
// provider มาจากเซิร์ฟเวอร์ (booking.payment_gateway) ไม่ใช่ env ฝั่งเว็บ — สลับ
// โหมดแล้วทุกหน้าต้องเปลี่ยนพร้อมกัน ไม่ใช่รอ build ใหม่
const gateway = computed(() => booking.value?.payment_gateway || { provider: 'manual', methods: [] });
const useBeam = computed(() => gateway.value.provider === 'beam');

// แอปธนาคารที่เปิดรับ — กรองด้วยรายการที่เซิร์ฟเวอร์บอกมา ไม่ hardcode
const ALL_BANK_APPS = [
  { type: 'KPLUS', label: 'K PLUS', bank: 'กสิกรไทย' },
  { type: 'SCB_EASY', label: 'SCB EASY', bank: 'ไทยพาณิชย์' },
  { type: 'KRUNGSRI_APP', label: 'Krungsri', bank: 'กรุงศรีอยุธยา' },
  { type: 'BANGKOK_BANK_APP', label: 'Bualuang', bank: 'กรุงเทพ' },
];
const bankApps = computed(() =>
  ALL_BANK_APPS.filter((app) => gateway.value.methods?.includes(app.type))
);

// เงินเข้าแล้ว: ปลดนาฬิกาถอยหลังของที่นั่งก่อน ไม่งั้น handlePaymentExpiry จะยิง
// ยกเลิกการจองที่เพิ่งจ่ายไปหมาดๆ
const {
  payment: beamPayment,
  loading: beamLoading,
  error: beamError,
  qrSrc: beamQrSrc,
  expired: beamExpired,
  failed: beamFailed,
  settling: beamSettling,
  settlingSeconds: beamSettlingSeconds,
  slow: beamSlow,
  countdownText: beamCountdownText,
  resumeWaiting: resumeBeamWaiting,
  stop: stopBeamTimers,
  create: createBeamPayment,
} = useBeamCharge(() => {
  seatsStore.offExpire(handlePaymentExpiry);
  seatsStore.clearSelection();
  router.push(`/confirmation/${booking.value.booking_ref}`);
});

/**
 * ออก QR ใบใหม่ — ยอดคำนวณที่เซิร์ฟเวอร์ทั้งหมด เราส่งไปแค่ "จ่ายแบบไหน"
 */
function createBeamCharge(methodType = 'QR_PROMPT_PAY') {
  if (!booking.value) return;

  const payload = {
    booking_ref: booking.value.booking_ref,
    purpose: paymentType.value,
    payment_method_type: methodType,
  };
  if (paymentType.value === 'installment') {
    payload.installment_count = selectedInstallmentCount.value;
  }

  return createBeamPayment(payload);
}

// ── Deposit helpers ──────────────────────────────────────────
const depositAvailable = computed(() => !!quote.value?.deposit?.available);
const depositAmount = computed(() => Number(quote.value?.deposit?.amount || 0));
const balanceAmount = computed(() => Number(quote.value?.deposit?.balance || 0));
const balanceDueDateText = computed(() => {
  const due = quote.value?.deposit?.balance_due_at;
  if (!due) return '-';
  return new Date(due).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
});
const depositTierDiscountPercent = computed(() =>
  Number(quote.value?.deposit?.tier_discount_percent || 0)
);
const depositPercentText = computed(() => {
  const pct = quote.value?.deposit?.percent_of_total;
  if (!pct) return '';
  const base = `ประมาณ ${pct}% ของยอดรวม`;
  return depositTierDiscountPercent.value > 0
    ? `${base} · ลดให้แล้ว ${depositTierDiscountPercent.value}% ตามระดับสมาชิก`
    : base;
});

// ── Installment helpers ──────────────────────────────────────
// จำนวนงวดที่เลือกได้และยอดต่องวดมาจาก payment_options.installment ฝั่งเซิร์ฟเวอร์
const installmentQuote = computed(() => quote.value?.installment || null);
const installmentAvailable = computed(() => !!installmentQuote.value?.available);
const maxInstallmentCount = computed(() =>
  Math.max(installmentQuote.value?.max_count || 0, 2)
);
const installmentIntervalDays = computed(() =>
  installmentQuote.value?.interval_days ?? 15
);
// ต้องปิดยอดก่อนเดินทางกี่วัน + งวดสุดท้ายตรงกับวันไหน (เซิร์ฟเวอร์เป็นคนกำหนด)
const installmentLeadDays = computed(() => installmentQuote.value?.lead_days ?? 15);
const installmentFinalDueDate = computed(() => {
  const option = selectedInstallmentOption.value;
  const dates = option?.due_dates || [];
  return dates[dates.length - 1] || installmentQuote.value?.final_due_date || null;
});
const selectedInstallmentCount = ref(3);

// Days from today to trip departure
const daysUntilTrip = computed(() => {
  if (!booking.value?.schedule?.departure_date) return Infinity;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const dep = new Date(booking.value.schedule.departure_date);
  dep.setHours(0, 0, 0, 0);
  return Math.floor((dep - today) / (1000 * 60 * 60 * 24));
});

// Installment options filtered by what's actually feasible
const availableInstallmentOptions = computed(() =>
  (installmentQuote.value?.options || []).map((option) => option.count)
);

// ผ่อนชำระเปิดให้ทุกรอบอัตโนมัติ เหลือเหตุผลเดียวที่เลือกไม่ได้คือทริปใกล้เกินไป
const installmentNotAvailable = computed(() => {
  if (!booking.value || booking.value.is_join_trip) return false;
  return availableInstallmentOptions.value.length === 0;
});

const installmentWarningMessage = computed(() => {
  const days = daysUntilTrip.value;
  const lead = installmentLeadDays.value;
  if (installmentNotAvailable.value) {
    return `ทริปนี้ออกเดินทางในอีก ${days} วัน และยอดผ่อนต้องปิดก่อนเดินทาง ${lead} วัน จึงเหลือเวลาไม่พอแบ่งงวดครับ`;
  }
  if (!installmentAvailable.value) return '';
  return `ระบบแบ่งงวดให้อัตโนมัติจากเวลาที่เหลือ — ผ่อนได้สูงสุด ${maxInstallmentCount.value} งวด งวดสุดท้ายครบกำหนด ${formatDate(installmentFinalDueDate.value)}`;
});
const selectedInstallmentOption = computed(() =>
  (installmentQuote.value?.options || []).find(
    (option) => option.count === selectedInstallmentCount.value
  ) || null
);
const perInstallment = computed(() => Number(selectedInstallmentOption.value?.per_amount || 0));
const currentPayAmount = computed(() => {
  if (!booking.value) return 0;
  const total = Number(quote.value?.full?.amount ?? booking.value.total_amount);
  // ยอดของรูปแบบที่เลือกต้องมาจากเซิร์ฟเวอร์เสมอ — ถ้าไม่มี (เช่นรูปแบบนั้นใช้ไม่ได้
  // แล้ว) ให้ตกกลับไปเป็นเต็มจำนวน ดีกว่าปล่อยให้ QR ขึ้นยอด 0
  if (paymentType.value === 'installment') return perInstallment.value || total;
  if (paymentType.value === 'deposit') return depositAmount.value || total;
  return total;
});

const payOptionsCount = computed(() => {
  let n = 1;
  if (depositAvailable.value) n++;
  if (installmentAvailable.value) n++;
  return n;
});

const minPerInstallmentPreview = computed(() => {
  const options = installmentQuote.value?.options || [];
  if (options.length) return Number(options[options.length - 1].per_amount || 0);
  // ผ่อนไม่ได้แล้ว (ทริปใกล้เกินไป) — การ์ดที่จางลงยังโชว์ยอดคร่าว ๆ ตามจำนวนงวดของรอบ
  const total = Number(booking.value?.total_amount || 0);
  const scheduleMax = Math.min(booking.value?.schedule?.installment_count || 2, 6);
  return Math.round(total / Math.max(2, scheduleMax));
});

// วันครบกำหนดมาจากเซิร์ฟเวอร์ (due_dates) ไม่คำนวณเองอีกแล้ว — งวดไม่ได้ห่างกัน
// เท่ากันแบบเดินทีละ 30 วัน แต่ถูกหารจากเวลาที่เหลือถึงวันปิดยอด
const installmentSchedule = computed(() => {
  const option = selectedInstallmentOption.value;
  if (!option) return [];
  const n = option.count;
  const dueDates = option.due_dates || [];
  return Array.from({ length: n }, (_, index) => ({
    no: index + 1,
    dueDate: dueDates[index] || null,
    amount: index === n - 1 ? Number(option.last_amount) : Number(option.per_amount),
  }));
});

// Reset to 'full' if installment is selected but becomes unavailable
watch(installmentNotAvailable, (notAvailable) => {
  if (notAvailable && paymentType.value === 'installment') {
    paymentType.value = 'full';
  }
});

// เช่นเดียวกับมัดจำ — รูปแบบที่เซิร์ฟเวอร์บอกว่าใช้ไม่ได้ ต้องไม่ค้างเป็นตัวเลือกที่เลือกอยู่
watch(depositAvailable, (available) => {
  if (!available && paymentType.value === 'deposit') {
    paymentType.value = 'full';
  }
});

// Auto-cap selectedInstallmentCount when available options shrink
watch(availableInstallmentOptions, (opts) => {
  if (opts.length > 0 && !opts.includes(selectedInstallmentCount.value)) {
    selectedInstallmentCount.value = opts[opts.length - 1];
  }
});

// ── QR regenerates when paymentType, paymentMethod, or installment count changes ─
// ยอดเปลี่ยน = QR ใบเดิมใช้ไม่ได้แล้ว ต้องออกใบใหม่ทั้งสองโหมด
watch([paymentType, paymentMethod, selectedInstallmentCount], ([, method]) => {
  // ตอนเปิดหน้ายังไม่ใช่ "ลูกค้าเปลี่ยนใจ" — onMounted เป็นคนออก QR ใบแรกเอง
  // ไม่งั้นการกู้รูปแบบที่บันทึกไว้จะยิงสร้าง charge ซ้ำอีกใบทันทีที่โหลดหน้า
  if (restoringPlan) return;
  if (method !== 'promptpay') return;
  if (useBeam.value) {
    createBeamCharge('QR_PROMPT_PAY');
  } else {
    nextTick(generateQR);
  }
});

/**
 * กลับไปยังรูปแบบการชำระที่การจองนี้เลือกไว้แล้ว
 *
 * หลังบ้านบันทึก payment_type ตั้งแต่ตอนออก QR (BeamPaymentService) หรือตอนรับสลิป
 * (PaymentController::charge) การจองที่ยังค้างอยู่ — ออก QR มัดจำแล้วยังไม่จ่าย หรือ
 * ส่งสลิปมัดจำแล้วยอดไม่ตรงจนถูกกันไว้ตรวจ — เปิดหน้านี้ใหม่จึงต้องกลับมาที่ "จ่ายมัดจำ"
 * ไม่ใช่เด้งกลับไป "ชำระเต็มจำนวน" แล้วโชว์ยอดเต็มให้ลูกค้าที่ตั้งใจจ่ายมัดจำโอนเกิน
 *
 * 'full' เป็นค่า default ของคอลัมน์ ไม่ใช่ตัวเลือกที่ลูกค้ากด จึงไม่ต้องกู้อะไร และ
 * การแบ่งจ่ายกลุ่มยืม payment_type = 'deposit' ไปใช้ ต้องไม่ตีความว่าเป็นมัดจำธรรมดา
 */
function restorePaymentPlan() {
  const recorded = booking.value?.payment_type;

  if (recorded === 'installment' && installmentAvailable.value) {
    paymentType.value = 'installment';
    const recordedCount = Number(booking.value?.installment_count || 0);
    if (availableInstallmentOptions.value.includes(recordedCount)) {
      selectedInstallmentCount.value = recordedCount;
    }

    return;
  }

  if (recorded === 'deposit' && depositAvailable.value && !booking.value?.split?.enabled) {
    paymentType.value = 'deposit';
  }
}

// ── PromptPay QR ─────────────────────────────────────────────
function buildPromptPayPayload(identifier, amount) {
  const cleanId = identifier.replace(/\D/g, '');
  let normalized = cleanId;
  let typeTag = '03'; // Default to e-Wallet (15 digits)
  
  if (cleanId.length === 10 && cleanId.startsWith('0')) {
    normalized = '0066' + cleanId.slice(1);
    typeTag = '01'; // Mobile
  } else if (cleanId.length === 13) {
    typeTag = '02'; // ID Card / Tax ID
  }
  
  const tag = (id, value) => {
    const len = value.length.toString().padStart(2, '0');
    return `${id}${len}${value}`;
  };
  
  const merchantAccInfo = tag('00', 'A000000677010111') + tag(typeTag, normalized);
  const merchantInfo = tag('29', merchantAccInfo);
  const amtStr = amount.toFixed(2);
  let payload =
    tag('00', '01') +
    tag('01', '12') +
    merchantInfo +
    tag('53', '764') +
    tag('54', amtStr) +
    tag('58', 'TH') +
    tag('62', tag('07', 'LUILAYKHAO')) +
    '6304';
  const crc = crc16(payload);
  return payload + crc;
}

function crc16(str) {
  let crc = 0xffff;
  for (let i = 0; i < str.length; i++) {
    crc ^= str.charCodeAt(i) << 8;
    for (let j = 0; j < 8; j++) {
      crc = crc & 0x8000 ? (crc << 1) ^ 0x1021 : crc << 1;
    }
  }
  return ((crc & 0xffff).toString(16).toUpperCase()).padStart(4, '0');
}

async function generateQR() {
  await nextTick();
  if (!qrCanvas.value || !booking.value) return;
  
  const amount = currentPayAmount.value;

  qrGenerated.value = false;
  const payload = buildPromptPayPayload('004999239362071', amount);
  
  const ctx = qrCanvas.value.getContext('2d');
  const bgImg = new Image();
  bgImg.src = '/images/IMG_7195.JPG';
  
  bgImg.onload = async () => {
    // Set canvas dimensions to match image
    qrCanvas.value.width = bgImg.width;
    qrCanvas.value.height = bgImg.height;
    
    // Draw template background
    ctx.drawImage(bgImg, 0, 0);
    
    // Create temporary canvas for QR code
    const tempCanvas = document.createElement('canvas');
    const qrSize = bgImg.width * 0.52; // QR size relative to template width
    
    await QRCode.toCanvas(tempCanvas, payload, {
      width: qrSize,
      margin: 0,
      color: { 
        dark: '#000000', 
        light: '#ffffff' 
      },
      errorCorrectionLevel: 'H'
    });
    
    // Position QR code in the white box of the template
    // Coordinates calibrated for IMG_7195.JPG layout
    const x = (bgImg.width - qrSize) / 2;
    const y = bgImg.height * 0.245; 
    
    ctx.drawImage(tempCanvas, x, y);
    
    qrGenerated.value = true;
  };
}

function saveQR() {
  if (!qrCanvas.value) return;
  const link = document.createElement('a');
  link.download = 'promptpay-qr.png';
  link.href = qrCanvas.value.toDataURL('image/png');
  link.click();
}

function onSlipChange(e) {
  const file = e.target.files[0];
  if (!file) return;
  setFile(file);
}

function removeSlip() {
  slipFile.value = null;
  slipPreview.value = null;
  slipChecked.value = false;
  slipAmountMatched.value = false;
  slipDetectedAmount.value = null;
  transferDate.value = '';
  transferTime.value = '';
  if (slipInputRef.value) slipInputRef.value.value = '';
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' });
}

function formatRegion(slug) {
  if (!slug) return '';
  const map = {
    bangkok: 'กรุงเทพฯ',
    central: 'ภาคกลาง',
    north: 'ภาคเหนือ',
    northeast: 'ภาคอีสาน',
    east: 'ภาคตะวันออก',
    west: 'ภาคตะวันตก',
    south: 'ภาคใต้'
  };
  return map[slug] || slug;
}

function initPaymentCountdown() {
  const deadline = paymentDeadline.value;

  // ไม่มีเส้นตาย = ไม่ต้องนับถอยหลัง (จ่ายแล้ว ยกเลิกแล้ว หรือส่งสลิปแล้วรอแอดมินตรวจ)
  if (!deadline) {
    seatsStore.clearSelection();
    return;
  }

  const createdAtMs = booking.value.created_at ? new Date(booking.value.created_at).getTime() : Date.now();

  seatsStore.setActiveBookingInfo({
    tripTitle: booking.value.schedule?.trip?.title || 'กิจกรรม',
    scheduleId: booking.value.schedule_id ?? booking.value.schedule?.id,
    bookingRef: booking.value.booking_ref,
    step: 'payment',
    startedAt: Number.isFinite(createdAtMs) ? createdAtMs : Date.now(),
  });
  seatsStore.setDeadline(deadline.toISOString(), paymentWindowSeconds.value);
  seatsStore.startCountdown();
}

async function handlePaymentExpiry() {
  if (autoCancelling.value) return;

  // จ่ายไปแล้วแต่ผลยังไม่กลับมา — ห้ามยกเลิกการจองจากฝั่งเว็บเด็ดขาด ไม่งั้นเงินที่
  // เข้ามาทีหลังจะไปตกที่การจองที่ถูกยกเลิกไปแล้ว (settle() ต้องไปตั้งธงรอคนคืนเงิน)
  // ปล่อยให้หลังบ้านเป็นคนตัดสิน มันเห็นทั้ง webhook และ reconcile ที่เว็บไม่เห็น
  if (beamSettling.value) return;

  // ส่งสลิปมาแล้วและรอแอดมินตรวจยอด — หลังบ้านกัน timer ไว้ให้ ฝั่งเว็บก็ต้องไม่ยกเลิกเอง
  if (booking.value?.slip_ocr_status) return;

  autoCancelling.value = true;

  try {
    if (booking.value?.booking_ref && booking.value?.status === 'pending') {
      await bookingStore.cancelBooking(
        booking.value.booking_ref,
        `หมดเวลาชำระเงินเกิน ${paymentWindowMinutes.value} นาที ระบบยกเลิกการจองอัตโนมัติ`
      );
      booking.value.status = 'cancelled';
    }
  } catch (e) {
    console.error('Auto-cancel booking failed:', e);
  } finally {
    seatsStore.clearSelection();
  }

  await swal.error(
    'หมดเวลาชำระเงินแล้ว',
    `ครบกำหนด ${paymentWindowMinutes.value} นาที ระบบได้ยกเลิกการจองและคืนที่นั่งเรียบร้อยแล้ว กรุณาทำรายการใหม่อีกครั้ง`
  );
  router.push('/trips');
}

async function processPayment() {
  if (paying.value) return; // กันกดซ้ำ / ชนกับการยืนยันอัตโนมัติ
  // เช็คเฉพาะรายการที่ยังมีเส้นตายอยู่จริง — สลิปที่ถูกตีกลับไม่มีนาฬิกาแล้ว
  // แต่ยังต้องส่งใหม่ได้ ไม่ใช่โดนบล็อกว่า "หมดเวลา"
  if (paymentDeadline.value && seatsStore.countdownSeconds <= 0) {
    paymentError.value = 'หมดเวลาชำระเงินแล้ว ระบบได้ยกเลิกการจองอัตโนมัติ';
    return;
  }

  if (booking.value?.status !== 'pending') {
    paymentError.value = 'สถานะการจองนี้ไม่สามารถชำระเงินได้';
    return;
  }

  if (!slipFile.value) {
    paymentError.value = 'กรุณาอัปโหลดสลิปการโอนเงินก่อนกดชำระ';
    return;
  }
  paying.value = true;
  paymentError.value = '';
  try {
    const fd = new FormData();
    fd.append('booking_ref', booking.value.booking_ref);
    fd.append('payment_type', paymentType.value);
    fd.append('payment_method', paymentMethod.value);
    fd.append('amount', currentPayAmount.value);
    if (paymentType.value === 'installment') {
      fd.append('installment_count', selectedInstallmentCount.value);
    }
    fd.append('slip_image', slipFile.value);
    if (transferDate.value) fd.append('transfer_date', transferDate.value);
    if (transferTime.value) fd.append('transfer_time', transferTime.value);

    await bookingStore.chargePayment(fd);
    seatsStore.offExpire(handlePaymentExpiry);
    seatsStore.clearSelection();
    router.push(`/confirmation/${booking.value.booking_ref}`);
  } catch (e) {
    paymentError.value = e?.response?.data?.message || 'การชำระเงินล้มเหลว กรุณาลองใหม่';
  } finally {
    paying.value = false;
  }
}

onMounted(async () => {
  seatsStore.onExpire(handlePaymentExpiry);

  try {
    restoringPlan = true;
    booking.value = await bookingStore.fetchBooking(route.params.bookingRef);
    addPaymentInfo({
      bookingRef: booking.value?.booking_ref,
      value: booking.value?.total_amount,
      paymentType: booking.value?.payment_type,
    });
    // จำนวนงวดตั้งต้น = งวดสูงสุดที่ผ่อนได้จริงตามที่เซิร์ฟเวอร์คำนวณมา
    if (installmentQuote.value?.default_count) {
      selectedInstallmentCount.value = installmentQuote.value.default_count;
    }
    restorePaymentPlan();
    initPaymentCountdown();
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
    // รอให้ watcher ที่ค้างอยู่จากการกู้ค่าข้างบน flush ไปก่อน แล้วค่อยปลดธง
    await nextTick();
    restoringPlan = false;
  }
  if (paymentMethod.value === 'promptpay') {
    if (useBeam.value) {
      await createBeamCharge('QR_PROMPT_PAY');
    } else {
      await nextTick();
      generateQR();
    }
  }
});

onBeforeUnmount(() => {
  seatsStore.offExpire(handlePaymentExpiry);
  stopBeamTimers();
});
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.25s ease, transform 0.25s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
