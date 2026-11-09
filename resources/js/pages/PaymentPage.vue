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
  <div v-else class="font-['DB Heavent', 'Anuphan'] bg-[#f9f9f9] min-h-screen pt-8 pb-32 lg:pb-24 px-4 md:px-8 lg:px-12">
    <!-- Progress Stepper -->
    <div class="flex items-center justify-center mb-12 max-w-7xl mx-auto pay-rise">
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
        <div class="h-[2px] flex-1 bg-[#b4eae9] overflow-hidden"><div class="h-full bg-[#006565] pay-line"></div></div>
        <div class="flex flex-col items-center flex-1">
          <div class="w-10 h-10 rounded-full bg-[#006565] text-white flex items-center justify-center mb-2 font-bold text-sm ring-4 ring-[#93f2f2]/40 pay-current">3</div>
          <span class="text-xs font-bold text-[#006565]">ชำระเงิน</span>
        </div>
      </div>
    </div>

    <!-- ทีมงานกันที่นั่งไว้ให้ — ไม่ต้องนับถอยหลังสิบนาทีแบบจองเอง -->
    <div v-if="isOnHold" class="max-w-7xl mx-auto mb-10 pay-rise" style="--d: 60ms">
      <div class="bg-teal-50 border border-teal-100 rounded-3xl p-5 md:p-6 flex items-center gap-4">
        <div class="w-12 h-12 rounded-full bg-teal-100 flex items-center justify-center shrink-0">
          <span class="material-symbols-rounded text-[#006565] text-2xl">event_seat</span>
        </div>
        <div>
          <h3 class="text-[#004b4b] font-bold text-base md:text-lg">ทีมงานกันที่นั่งไว้ให้คุณแล้ว</h3>
          <p class="text-[#006565] text-sm">
            ชำระเงินได้ถึง {{ holdUntilLabel }} ยังไม่ต้องรีบครับ ถ้าต้องการเวลาเพิ่มทักทีมงานได้เลย
          </p>
        </div>
      </div>
    </div>

    <!-- Urgency Message & Timer -->
    <div v-if="seatsStore.countdownSeconds > 0" class="max-w-7xl mx-auto mb-10 pay-rise" style="--d: 60ms">
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
        <div class="bg-white rounded-3xl p-6 md:p-8 border border-gray-100 pay-rise" style="--d: 120ms">
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

        <!-- ── รูปแบบการชำระ (ขึ้นเฉพาะตอนมีให้เลือกมากกว่าจ่ายเต็ม) ──
             รายการแถวเดียวกับแอป: เลือกแล้วแถวนั้นกางรายละเอียดของตัวเองออกมาข้างใต้ -->
        <section v-if="installmentAvailable || installmentNotAvailable || depositAvailable" class="bg-white rounded-3xl px-5 pt-5 pb-1 sm:px-8 sm:pt-7 border border-gray-100 pay-rise" style="--d: 180ms">
          <h2 class="pay-section-title">
            <span class="pay-section-icon"><span class="material-symbols-rounded icon-20" style="font-variation-settings:'FILL' 1">credit_card</span></span>
            รูปแบบการชำระ
          </h2>

          <div class="divide-y divide-gray-100" role="radiogroup" aria-label="รูปแบบการชำระ">
            <PayMethodRow icon="payments" title="ชำระเต็มจำนวน"
              :subtitle="`ยอดชำระ ฿${money(booking.total_amount)} · จบในครั้งเดียว ยืนยันที่นั่งทันที`"
              :selected="paymentType === 'full'" @select="paymentType = 'full'" />

            <PayMethodRow v-if="depositAvailable" icon="savings"
              :title="`จ่ายมัดจำ ฿${money(depositAmount)}`"
              :subtitle="`ส่วนที่เหลือ ฿${money(balanceAmount)} · ภายใน ${balanceDueDateText}`"
              :selected="paymentType === 'deposit'" @select="paymentType = 'deposit'">
              <div class="space-y-3">
                <!-- สรุปยอดมัดจำ -->
                <div class="rounded-2xl bg-gray-50 p-4 space-y-2.5">
                  <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="flex items-center gap-2.5 text-gray-500 font-medium"><span class="pay-step">1</span>ยอดรวมทั้งหมด</span>
                    <span class="font-bold text-gray-900 tabular-nums">฿{{ money(booking.total_amount) }}</span>
                  </div>
                  <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="flex items-center gap-2.5 text-gray-900 font-bold"><span class="pay-step is-now">2</span>มัดจำ ชำระตอนนี้</span>
                    <span class="text-base font-black text-accent tabular-nums">฿{{ money(depositAmount) }}</span>
                  </div>
                  <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="flex items-center gap-2.5 text-gray-500 font-medium"><span class="pay-step is-later">3</span>ส่วนที่เหลือ ภายใน {{ balanceDueDateText }}</span>
                    <span class="font-bold text-amber-700 tabular-nums">฿{{ money(balanceAmount) }}</span>
                  </div>
                  <p v-if="depositPercentText" class="pl-[34px] text-[12px] font-semibold text-accent">{{ depositPercentText }}</p>
                  <p class="flex items-start gap-2 pt-1 text-[12px] text-gray-500 font-medium leading-relaxed">
                    <span class="material-symbols-rounded icon-16 text-gray-400 shrink-0 mt-px">notifications_active</span>
                    เราจะส่ง SMS + อีเมล แจ้งเตือนล่วงหน้า 5 วัน, 2 วัน และวันครบกำหนด
                  </p>
                </div>

                <!-- เงื่อนไขมัดจำ — ข้อที่ลูกค้าต้องรู้ก่อนจ่าย จึงอยู่ติดกับตัวเลือก -->
                <div class="rounded-2xl border border-red-200 bg-red-50/60 p-4 flex gap-3">
                  <span class="material-symbols-rounded icon-22 text-red-600 shrink-0" style="font-variation-settings:'FILL' 1">gavel</span>
                  <div class="text-[13px] text-red-800 leading-relaxed">
                    <p class="font-black text-red-900 mb-1">เงื่อนไขสำคัญ</p>
                    <p>
                      กรณีขอยกเลิกการเดินทางเอง ทางทริปขอสงวนสิทธิ์ <strong class="text-red-900">ไม่คืนเงินมัดจำ</strong>
                      เนื่องจากมีการนำไปสำรองจ่ายค่าอุทยานและยานพาหนะล่วงหน้า
                      เลื่อนวันเดินทางได้ {{ POLICY.postponeTimes }} ครั้ง โดยแจ้งล่วงหน้าอย่างน้อย {{ POLICY.postponeNoticeDays }} วัน
                      (<router-link to="/terms" class="font-bold underline">เงื่อนไขฉบับเต็ม</router-link>)
                    </p>
                    <p class="mt-2">
                      ต้องชำระยอดส่วนที่เหลือ <strong class="text-red-900">ก่อนเดินทาง {{ POLICY.balanceDueDays }} วัน</strong> (ภายในวันที่ <strong>{{ balanceDueDateText }}</strong>) มิฉะนั้นถือว่าสละสิทธิ์การเดินทาง
                    </p>
                  </div>
                </div>
              </div>
            </PayMethodRow>

            <!-- ผ่อนไม่ทันก่อนเดินทาง — ยังโชว์แถวไว้ (จาง) พร้อมเหตุผล -->
            <PayMethodRow v-if="installmentNotAvailable" icon="calendar_month" title="ผ่อนชำระ"
              :subtitle="installmentWarningMessage" :available="false">
              <template #below>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-gray-100 text-[11px] font-bold text-gray-500">
                  <span class="material-symbols-rounded icon-14">event_busy</span>ไม่พร้อมใช้
                </span>
              </template>
            </PayMethodRow>

            <PayMethodRow v-else-if="installmentAvailable" icon="calendar_month"
              :title="`ผ่อนชำระ ${selectedInstallmentCount} งวด`"
              :subtitle="`งวดละ ฿${money(perInstallment)}${installmentFinalDueDate ? ` · ปิดยอด ${formatDate(installmentFinalDueDate)}` : ''} · ไม่มีดอกเบี้ย`"
              :selected="paymentType === 'installment'" @select="paymentType = 'installment'">
              <div class="space-y-4">
                <!-- เลือกจำนวนงวด — การ์ด "3 × ฿1,200" เห็นยอดต่องวดของทุกตัวเลือกพร้อมกัน -->
                <div v-if="installmentOptions.length > 1">
                  <p class="mb-2 text-[13px] font-semibold text-gray-500">แบ่งเป็นกี่งวด</p>
                  <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5">
                    <button v-for="opt in installmentOptions" :key="opt.count" type="button"
                      @click="selectedInstallmentCount = opt.count"
                      class="pay-count text-left rounded-xl px-3.5 py-3 border-[1.5px] transition-colors"
                      :class="selectedInstallmentCount === opt.count
                        ? 'bg-accent-tint border-accent'
                        : 'bg-gray-50 border-transparent hover:border-gray-200'"
                      :aria-pressed="selectedInstallmentCount === opt.count">
                      <span class="block text-base font-black tabular-nums whitespace-nowrap"
                        :class="selectedInstallmentCount === opt.count ? 'text-accent' : 'text-gray-900'">
                        {{ opt.count }} × ฿{{ money(opt.per_amount) }}
                      </span>
                      <span v-if="opt.due_dates?.length" class="block mt-0.5 text-[12px] text-gray-500 font-medium truncate">
                        ปิดยอด {{ thaiShort(opt.due_dates[opt.due_dates.length - 1]) }}
                      </span>
                    </button>
                  </div>
                </div>

                <!-- ตารางงวด -->
                <ol class="rounded-2xl bg-gray-50 divide-y divide-gray-100 overflow-hidden">
                  <li v-for="inst in installmentSchedule" :key="inst.no" class="flex items-center gap-3 px-4 py-3">
                    <span class="pay-step" :class="inst.no === 1 ? 'is-now' : ''">{{ inst.no }}</span>
                    <span class="flex-1 min-w-0">
                      <span class="block text-sm font-bold text-gray-900">งวดที่ {{ inst.no }}</span>
                      <span class="block text-[12px] text-gray-500 font-medium">{{ inst.dueDate ? formatDate(inst.dueDate) : '-' }}</span>
                    </span>
                    <span class="text-right">
                      <span class="block text-sm font-black tabular-nums" :class="inst.no === 1 ? 'text-accent' : 'text-gray-900'">฿{{ money(inst.amount) }}</span>
                      <span class="block text-[11px] font-bold" :class="inst.no === 1 ? 'text-accent' : 'text-gray-400'">{{ inst.no === 1 ? 'ชำระตอนนี้' : 'รอชำระ' }}</span>
                    </span>
                  </li>
                </ol>

                <p class="flex items-start gap-2 text-[12px] text-gray-500 font-medium leading-relaxed">
                  <span class="material-symbols-rounded icon-16 text-gray-400 shrink-0 mt-px">auto_awesome</span>
                  {{ installmentWarningMessage }} · ยอดรวมคงเดิม ไม่มีดอกเบี้ย
                </p>

                <div class="rounded-2xl border border-red-200 bg-red-50/60 p-4 flex gap-3">
                  <span class="material-symbols-rounded icon-22 text-red-600 shrink-0">priority_high</span>
                  <p class="text-[13px] text-red-800 leading-relaxed">
                    <strong class="text-red-900">เงื่อนไขการยกเลิก:</strong> หากไม่ชำระตามตารางภายในวันครบกำหนด ลุยเลเขาขอสงวนสิทธิ์ยกเลิกทริปและไม่คืนเงินทุกกรณี เพื่อความมั่นใจของเพื่อนร่วมทริปท่านอื่น
                  </p>
                </div>
              </div>
            </PayMethodRow>
          </div>
        </section>

        <!-- ── ช่องทางชำระเงิน ──
             แถวที่เลือกกางรายละเอียดของตัวเองข้างใต้ (QR ใต้ QR PromptPay, เลขบัญชีใต้โอนธนาคาร)
             โหมดเกตเวย์: QR เลือกไว้เสมอ บัตร/วอลเล็ต/ผ่อนเป็นแถวลูกศรไปหน้าของ Beam
             และแอปธนาคารพับไว้เป็นแถบโลโก้ท้ายรายการ -->
        <section class="bg-white rounded-3xl px-5 pt-5 pb-1 sm:px-8 sm:pt-7 border border-gray-100 pay-rise" style="--d: 240ms">
          <h2 class="pay-section-title">
            <span class="pay-section-icon"><span class="material-symbols-rounded icon-20" style="font-variation-settings:'FILL' 1">account_balance_wallet</span></span>
            ช่องทางชำระเงิน
          </h2>

          <div class="divide-y divide-gray-100" role="radiogroup" aria-label="ช่องทางชำระเงิน">
            <PayMethodRow badge="thaiqr.png" title="QR PromptPay"
              :subtitle="useBeam ? 'จ่ายแล้วระบบยืนยันที่นั่งให้อัตโนมัติ ไม่ต้องแนบสลิปและไม่ต้องรอเจ้าหน้าที่ตรวจ' : 'สแกนจ่ายผ่านแอปธนาคารหรือ e-Wallet ได้ทันที'"
              :selected="paymentMethod === 'promptpay'" @select="paymentMethod = 'promptpay'">
              <div class="flex flex-col items-center gap-4 rounded-3xl bg-gray-50 px-4 py-6 sm:py-8">
                <!-- QR จากเกตเวย์ — เงินเข้าแล้วรู้ทันที ไม่ต้องให้ใครมาตรวจสลิป -->
                <template v-if="useBeam">
                  <!-- ธนาคารตอบว่าไม่ผ่าน — บอกให้ชัดแทนที่จะปล่อยให้ QR ใบตายค้างอยู่ -->
                  <div v-if="beamFailed" class="w-full max-w-sm">
                    <div class="rounded-3xl border border-red-100 bg-white p-7 text-center">
                      <span class="material-symbols-rounded text-red-500 text-4xl">credit_card_off</span>
                      <p class="mt-3 text-base font-black text-gray-900">การชำระเงินไม่สำเร็จ</p>
                      <p class="mt-1.5 text-xs text-gray-500 font-medium leading-relaxed">
                        ธนาคารแจ้งว่ารายการนี้ไม่ผ่าน ยังไม่มีการตัดเงิน · สร้างรายการใหม่แล้วลองอีกครั้งได้เลย
                      </p>
                      <button @click="createBeamCharge('QR_PROMPT_PAY')"
                        class="mt-5 px-5 py-2.5 bg-accent text-white text-xs font-black rounded-2xl hover:bg-accent-mid active:scale-95 transition-all">
                        สร้าง QR ใหม่
                      </button>
                    </div>
                  </div>

                  <!--
                    จ่ายแล้วแต่ webhook ยังไม่มา — เดิมช่วงนี้หน้าจอค้างอยู่กับ QR ใบเดิม
                    จนลูกค้าไม่แน่ใจว่าจ่ายผ่านไหม เอา QR ออกไปเลยระหว่างนี้ กันจ่ายซ้ำ
                  -->
                  <div v-else-if="beamSettling" class="w-full max-w-sm">
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
                    <div class="relative p-2 bg-white rounded-3xl border border-gray-100 overflow-hidden w-full max-w-[280px] aspect-square flex items-center justify-center">
                      <img v-if="beamQrSrc && !beamExpired" :key="`qr-${beamPayment?.payment_id}`" :src="beamQrSrc" alt="QR พร้อมเพย์"
                        class="block rounded-2xl w-full h-auto mx-auto pay-qr" />
                      <span v-if="beamQrSrc && !beamExpired" :key="`scan-${beamPayment?.payment_id}`" class="pay-scan" aria-hidden="true"></span>

                      <div v-if="beamLoading" class="absolute inset-0 flex items-center justify-center bg-white/90 rounded-3xl">
                        <div class="w-10 h-10 rounded-full border-4 border-accent-tint border-t-accent animate-spin"></div>
                      </div>

                      <!-- QR หมดอายุก่อนที่นั่งจะถูกคืน ลูกค้าจึงยังกดออกใบใหม่ได้ -->
                      <div v-else-if="beamExpired" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-white/95 rounded-3xl px-6 text-center">
                        <span class="material-symbols-rounded text-4xl text-gray-300">qr_code_2</span>
                        <p class="text-sm font-bold text-gray-900">QR หมดอายุแล้ว</p>
                        <button @click="createBeamCharge('QR_PROMPT_PAY')"
                          class="px-5 py-2.5 bg-accent text-white text-xs font-black rounded-2xl hover:bg-accent-mid active:scale-95 transition-all">
                          สร้าง QR ใหม่
                        </button>
                      </div>
                    </div>

                    <div class="flex items-center justify-between gap-3 w-full max-w-xs bg-white px-4 py-3 rounded-2xl border border-gray-100">
                      <div>
                        <p class="text-[11px] font-bold text-gray-500">ยอดที่ต้องชำระ</p>
                        <p class="text-lg font-black text-accent tabular-nums leading-tight">฿{{ money(currentPayAmount) }}</p>
                      </div>
                      <p v-if="beamPayment && !beamExpired" class="flex items-center gap-1.5 text-xs font-bold text-gray-700">
                        <span class="w-2 h-2 rounded-full bg-accent-light animate-pulse"></span>
                        รอชำระ · <span class="tabular-nums">{{ beamCountdownText }}</span>
                      </p>
                    </div>

                    <!--
                      ขั้นตอนอยู่ตรงนี้ตั้งแต่ยังไม่จ่าย ไม่ต้องรอให้ใครกดปุ่มก่อน — แถวที่กำลัง
                      ทำงานอยู่หมุนตลอด หน้าจอจึงไม่มีวินาทีไหนที่ดูเหมือนค้าง และพอลูกค้าออกไป
                      จ่ายแล้วกลับเข้ามา การ์ดนี้จะเลื่อนไปขั้นถัดไปเอง
                    -->
                    <div v-if="beamPayment && !beamExpired" class="w-full max-w-sm">
                      <PaymentSettlingPanel stage="waiting" />
                    </div>
                  </template>

                  <p v-if="beamError" class="text-xs font-bold text-red-600 text-center">{{ beamError }}</p>
                </template>

                <template v-else>
                  <div class="relative p-2 bg-white rounded-3xl border border-gray-100 overflow-hidden w-full max-w-[280px]">
                    <canvas ref="qrCanvas" class="block rounded-2xl w-full h-auto mx-auto" :class="{ 'pay-qr': qrGenerated }"></canvas>
                    <span v-if="qrGenerated" class="pay-scan" aria-hidden="true"></span>
                    <div v-if="!qrGenerated" class="absolute inset-0 flex items-center justify-center bg-white/80 rounded-3xl">
                      <div class="w-10 h-10 rounded-full border-4 border-accent-tint border-t-accent animate-spin"></div>
                    </div>
                  </div>

                  <div class="w-full max-w-xs bg-white rounded-2xl border border-gray-100 divide-y divide-gray-100">
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                      <div>
                        <p class="text-[11px] font-bold text-gray-500">ยอดที่ต้องชำระ</p>
                        <p class="text-lg font-black text-accent tabular-nums leading-tight">฿{{ money(currentPayAmount) }}</p>
                      </div>
                      <button @click="copyAmount"
                        class="flex items-center gap-1 px-3 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-bold rounded-xl active:scale-95 transition-all">
                        <span class="material-symbols-rounded icon-16">content_copy</span>คัดลอก
                      </button>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                      <div>
                        <p class="text-[11px] font-bold text-gray-500">พร้อมเพย์ / e-Wallet</p>
                        <p class="text-sm font-bold text-gray-900 tabular-nums">004-99923936-2071</p>
                      </div>
                      <button @click="copyAccount"
                        class="flex items-center gap-1 px-3 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-bold rounded-xl active:scale-95 transition-all">
                        <span class="material-symbols-rounded icon-16">content_copy</span>คัดลอก
                      </button>
                    </div>
                  </div>

                  <button v-if="qrGenerated" @click="saveQR"
                    class="w-full max-w-xs flex items-center justify-center gap-2 px-5 py-3 bg-white border border-gray-200 text-accent text-sm font-black rounded-2xl hover:border-accent active:scale-[0.98] transition-all">
                    <span class="material-symbols-rounded icon-18">download</span> บันทึก QR Code
                  </button>
                </template>
              </div>
            </PayMethodRow>

            <!-- โอนเข้าบัญชีแล้วแนบสลิป — โหมดเกตเวย์ไม่มีแถวนี้ (แอปธนาคารอยู่ท้ายรายการแทน) -->
            <PayMethodRow v-if="!useBeam" badge="kbank" title="โอนผ่านบัญชีธนาคาร"
              subtitle="กสิกรไทย · โอนแล้วแนบสลิปผ่านทางหน้านี้"
              :selected="paymentMethod === 'mobile_banking'" @select="paymentMethod = 'mobile_banking'">
              <div class="rounded-3xl bg-gray-50 p-4 sm:p-5 space-y-2.5">
                <div class="flex items-center justify-between gap-3 px-4 py-3 bg-white rounded-2xl border border-gray-100">
                  <span class="text-xs font-bold text-gray-500">ธนาคาร</span>
                  <span class="text-sm font-bold text-gray-900">กสิกรไทย (KBANK)</span>
                </div>
                <div class="flex items-center justify-between gap-3 px-4 py-3 bg-white rounded-2xl border border-gray-100">
                  <span class="text-xs font-bold text-gray-500">ชื่อบัญชี</span>
                  <span class="text-sm font-bold text-gray-900">ลุยเลเขา</span>
                </div>
                <div class="flex items-center justify-between gap-3 px-4 py-3 bg-white rounded-2xl border border-gray-100">
                  <span class="text-xs font-bold text-gray-500">เลขที่บัญชี</span>
                  <span class="flex items-center gap-2">
                    <span class="text-base font-black text-gray-900 tracking-wider tabular-nums">230-1-39095-8</span>
                    <button @click="copyBankAccount" aria-label="คัดลอกเลขบัญชี"
                      class="p-1.5 rounded-lg bg-accent-tint text-accent hover:bg-emerald-100 transition-colors active:scale-90">
                      <span class="material-symbols-rounded icon-18">content_copy</span>
                    </button>
                  </span>
                </div>
                <p class="flex items-start gap-2 pt-1 text-[12px] text-gray-600 font-medium leading-relaxed">
                  <span class="material-symbols-rounded icon-16 text-amber-500 shrink-0 mt-px">info</span>
                  <span>โอนยอด <strong class="text-gray-900">฿{{ money(currentPayAmount) }}</strong> ให้ครบถ้วน แล้วอัปโหลดสลิปด้านล่าง ระบบจะตรวจยอดและยืนยันให้อัตโนมัติ</span>
                </p>
              </div>
            </PayMethodRow>

            <!--
              ไปจ่ายบนหน้าของ Beam แล้วกลับมาที่ /payment/return — ช่องทางที่ขึ้นจริงขึ้นกับ
              เครื่อง (Apple Pay เฉพาะ Safari, Google Pay เฉพาะ Chrome/Android)
            -->
            <PaymentCheckoutMethods v-if="checkoutAvailable"
              :amount="currentPayAmount"
              :installment-min="installmentMin"
              :loading="beamLoading"
              @open="createBeamCharge('CHECKOUT')" />

            <!-- แอปธนาคาร — พับไว้เป็นแถบโลโก้ คนส่วนใหญ่สแกน QR อยู่แล้ว -->
            <div v-if="useBeam && bankApps.length">
              <button type="button" @click="showBankApps = !showBankApps" :aria-expanded="showBankApps"
                class="w-full flex items-center gap-1.5 py-4 text-left group">
                <img v-for="app in bankApps" :key="app.type" :src="`/images/pay/${app.badge}.svg`" :alt="app.label"
                  class="h-[22px] w-[34px]" width="34" height="22" />
                <span class="flex-1 text-right text-sm font-bold text-gray-900 group-hover:text-accent transition-colors">เปิดแอปธนาคารโดยตรง</span>
                <span class="material-symbols-rounded icon-22 text-gray-400 transition-transform duration-200" :class="showBankApps ? 'rotate-90' : ''">chevron_right</span>
              </button>
              <div v-if="showBankApps" class="divide-y divide-gray-100 border-t border-gray-100 pay-swap">
                <PayMethodRow v-for="app in bankApps" :key="app.type" trail="chevron"
                  :badge="app.badge" :title="app.label" :subtitle="`ธนาคาร${app.bank}`"
                  :disabled="beamLoading" :busy="beamLoading && pendingBankApp === app.type"
                  @select="openBankApp(app.type)" />
                <p class="flex items-start gap-2 py-4 text-[12px] text-gray-500 font-medium leading-relaxed">
                  <span class="material-symbols-rounded icon-16 text-gray-400 shrink-0 mt-px">info</span>
                  ระบบจะพาไปที่แอปธนาคารพร้อมยอด ฿{{ money(currentPayAmount) }} จ่ายเสร็จแล้วกลับมาหน้านี้ได้เลย ที่นั่งจะถูกยืนยันให้อัตโนมัติ
                </p>
              </div>
            </div>
          </div>

          <!-- Divider -->
          <div v-if="!useBeam" class="flex items-center gap-4 mt-6 mb-8">
            <div class="flex-1 h-[2px] bg-gray-50"></div>
            <span class="text-[11px] text-gray-400 font-black uppercase tracking-[0.2em]">หลักฐานการโอนเงิน</span>
            <div class="flex-1 h-[2px] bg-gray-50"></div>
          </div>

          <!-- Slip Upload — โหมดเกตเวย์ไม่ต้องใช้ เงินเข้าแล้วระบบรู้เอง -->
          <div v-if="!useBeam" class="space-y-6 pb-5 sm:pb-7">
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
                  <!-- เซิร์ฟเวอร์รับเฉพาะรูป (กฎ image, max:5120) — เดิมเขียนว่ารับ PDF ด้วย -->
                  <p class="text-[11px] text-gray-400 font-bold uppercase tracking-widest">ไฟล์รูป JPG, PNG ขนาดไม่เกิน 5MB</p>
                </div>
              </template>

              <template v-else>
                 <div class="relative w-full max-h-[400px] group/preview">
                    <img :src="slipPreview" alt="slip" class="w-full h-full object-contain rounded-[2rem] bg-gray-100 pay-swap" />
                    <div class="absolute inset-0 bg-black/40 opacity-0 group-hover/preview:opacity-100 transition-opacity rounded-[2rem] flex items-center justify-center">
                       <p class="text-white font-bold text-sm bg-white/20 backdrop-blur-md px-4 py-2 rounded-full border border-white/30">คลิกเพื่อเปลี่ยนรูป</p>
                    </div>
                    <button @click.stop="removeSlip"
                      class="absolute top-4 right-4 w-10 h-10 bg-white/90 backdrop-blur-md text-red-600 rounded-full flex items-center justify-center hover:bg-red-600 hover:text-white transition-all z-20">
                      <span class="material-symbols-rounded text-[22px]">delete</span>
                    </button>
                    <!-- Success indicator -->
                    <div class="absolute bottom-4 left-4 bg-teal-600 text-white px-4 py-2 rounded-full text-xs font-black flex items-center gap-2 pay-pop" style="--d: 200ms">
                       <span class="material-symbols-rounded text-[18px]">check_circle</span>
                       อัปโหลดสำเร็จ
                    </div>
                 </div>
              </template>
            </div>
            
            <input ref="slipInputRef" type="file" accept="image/*" required class="hidden" @change="onSlipChange" />
            <p v-if="slipError" class="-mt-3 flex items-center gap-1.5 text-xs font-bold text-red-600">
              <span class="material-symbols-rounded text-[16px]">error</span>{{ slipError }}
            </p>

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
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pay-rise" style="--d: 300ms">
           <div class="flex items-center gap-4 p-6 bg-white rounded-3xl border border-gray-100">
             <div class="w-12 h-12 rounded-2xl bg-teal-50 text-teal-600 flex items-center justify-center shrink-0">
               <span class="material-symbols-rounded text-2xl" style="font-variation-settings:'FILL' 1">verified</span>
             </div>
             <div>
                <p class="text-sm font-bold text-gray-900">การชำระเงินที่ตรวจสอบได้</p>
                <p class="text-xs text-gray-500">เชื่อมต่อแบบเข้ารหัส และออกใบเสร็จอิเล็กทรอนิกส์ทุกครั้ง</p>
             </div>
           </div>
           <div class="flex items-center gap-4 p-6 bg-white rounded-3xl border border-gray-100">
             <div class="w-12 h-12 rounded-2xl bg-teal-50 text-teal-600 flex items-center justify-center shrink-0">
               <span class="material-symbols-rounded text-2xl" style="font-variation-settings:'FILL' 1">support_agent</span>
             </div>
             <div>
                <p class="text-sm font-bold text-gray-900">ทีมงานพร้อมช่วยเหลือ</p>
                <p class="text-xs text-gray-500">
                  สอบถามโทร <a :href="supportPhoneHref()" class="font-semibold text-teal-700">{{ supportPhone() }}</a> ({{ supportHours() }})
                </p>
             </div>
           </div>
        </div>

      </div>

      <!-- RIGHT: Booking Summary Card -->
      <aside class="lg:col-span-4 lg:sticky lg:top-24 lg:pb-20 pay-rise" style="--d: 160ms">
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
               <div v-if="booking.seats?.length" class="flex items-center gap-3 p-4 bg-gray-50 rounded-2xl border border-gray-100 pay-rise">
                 <div class="w-10 h-10 rounded-xl bg-white text-teal-600 flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-xl">airline_seat_recline_extra</span>
                 </div>
                 <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest leading-none">หมายเลขที่นั่ง</p>
                    <p class="text-sm font-bold text-gray-900">{{ booking.seats.map(s => s.seat_id).join(', ') }}</p>
                 </div>
               </div>
               
               <div class="flex items-center gap-3 p-4 bg-gray-50 rounded-2xl border border-gray-100 pay-rise">
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

               <div v-if="booking.selected_addons?.length" class="p-4 bg-amber-50/70 rounded-2xl border border-amber-100 pay-rise">
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

               <div v-if="booking.selected_rentals?.length" class="p-4 bg-sky-50/70 rounded-2xl border border-sky-100 pay-rise">
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
                       <span class="text-2xl font-black text-amber-600 leading-none tabular-nums">฿{{ payNowShown.toLocaleString() }}</span>
                       <p class="text-[10px] font-bold text-amber-500 leading-none mt-1">จากทั้งหมด {{ selectedInstallmentCount }} งวด</p>
                    </div>
                 </div>
               </template>

               <template v-else-if="paymentType === 'deposit'">
                 <div class="flex justify-between items-end bg-teal-50 rounded-2xl p-4 border border-teal-200">
                    <span class="text-xs font-bold text-teal-700">ชำระมัดจำตอนนี้</span>
                    <div class="text-right">
                       <span class="text-2xl font-black text-teal-700 leading-none tabular-nums">฿{{ payNowShown.toLocaleString() }}</span>
                       <p class="text-[10px] font-bold text-teal-600 leading-none mt-1">ส่วนที่เหลือ ฿{{ balanceAmount.toLocaleString() }}</p>
                    </div>
                 </div>
               </template>

               <template v-else>
                 <div class="flex justify-between items-end bg-teal-50 rounded-2xl p-5 border border-teal-100">
                    <span class="text-sm font-black text-teal-900 uppercase tracking-tight">ยอดชำระสุทธิ</span>
                    <span class="text-3xl font-black text-teal-600 leading-none tabular-nums">฿{{ payNowShown.toLocaleString() }}</span>
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
                    {{ beamSettling ? 'กำลังตรวจสอบการชำระเงิน' : 'สแกน QR หรือเลือกช่องทางอื่น แล้วรอสักครู่' }}
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
                <span class="pay-shine" aria-hidden="true"></span>
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

      </aside>
    </div>

        <!--
          Sticky Mobile Button — ต้องอยู่นอก <aside> ที่มีแอนิเมชัน pay-rise (transform)
          เพราะ element ที่มี transform กลายเป็นกรอบอ้างอิงของ position: fixed
          เดิมแถบนี้จึงไปโผล่ท้ายการ์ดสรุปแทนที่จะติดขอบล่างจอระหว่างแอนิเมชัน
        -->
        <div class="lg:hidden fixed bottom-0 left-0 right-0 px-4 pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] bg-white/80 backdrop-blur-xl border-t border-gray-100 z-[100]">
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

            <!-- ยังไม่แนบสลิป: เดิมปุ่มเป็นสีเทากดไม่ได้โดยไม่บอกเหตุผล (กล่องแนบอยู่ไกลกลางหน้า)
                 ตอนนี้กดแล้วเปิดเลือกรูปสลิปได้เลย -->
            <button v-else-if="!slipFile" @click="slipInputRef?.click()"
              class="w-full py-4 rounded-2xl bg-teal-600 text-white font-black flex items-center justify-center gap-2 active:scale-95 transition-all text-sm">
              <span class="material-symbols-rounded text-lg">upload_file</span>
              <span>แนบสลิปการโอน ฿{{ currentPayAmount.toLocaleString() }}</span>
            </button>

            <button v-else @click="processPayment"
              :disabled="paying || scanningSlip"
              class="w-full py-4 rounded-2xl bg-emerald-600 text-white font-black flex items-center justify-center gap-2 active:scale-95 transition-all text-sm disabled:bg-gray-100 disabled:text-gray-400">
              <template v-if="!paying && !scanningSlip">
                <span>ยืนยันการชำระ ฿{{ currentPayAmount.toLocaleString() }}</span>
                <span class="material-symbols-rounded text-lg">arrow_forward</span>
              </template>
              <div v-else class="w-5 h-5 rounded-full border-2 border-white/30 border-t-white animate-spin"></div>
            </button>
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
import PaymentCheckoutMethods from '../components/PaymentCheckoutMethods.vue';
import PayMethodRow from '../components/PayMethodRow.vue';
import { useSwal } from '../lib/swal';
import { addPaymentInfo } from '../lib/analytics';
import { useBeamCharge } from '../composables/useBeamCharge';
import { thaiShort } from '../lib/thaiDate';
import { POLICY } from '../lib/policy';
import { supportHours, supportPhone, supportPhoneHref } from '../lib/contact';

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

const isOnHold = computed(() => {
  const holdUntil = booking.value?.hold_until ? new Date(booking.value.hold_until) : null;

  return Boolean(
    booking.value?.status === 'pending'
    && holdUntil
    && !Number.isNaN(holdUntil.getTime())
    && holdUntil > new Date(),
  );
});

const holdUntilLabel = computed(() => {
  const holdUntil = booking.value?.hold_until ? new Date(booking.value.hold_until) : null;
  if (!holdUntil || Number.isNaN(holdUntil.getTime())) return '';

  return `${thaiShort(holdUntil)} ${holdUntil.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' })} น.`;
});

const paymentDeadline = computed(() => {
  const b = booking.value;
  if (!b || b.status !== 'pending') return null;
  // ส่งสลิปแล้ว = ถือที่นั่งไว้รอแอดมินตรวจ ไม่มีเส้นตาย (หลังบ้านก็ข้ามรายการพวกนี้)
  if (b.slip_ocr_status) return null;

  // ทีมงานกันที่นั่งไว้ให้ (แอดมินจองแทนแล้วข้ามหน้าชำระเงิน) — เส้นตายเป็นวัน
  // ไม่ใช่นาที และหลังบ้านเป็นคนปล่อยที่นั่งเอง เว็บจึงไม่ต้องนับถอยหลัง/ยกเลิกให้
  if (isOnHold.value) return null;

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
// แนบไฟล์ไม่ผ่าน (ไม่ใช่รูป / ใหญ่เกิน) — โชว์ใต้กล่องแนบสลิป ไม่ใช่ท้ายหน้า
const slipError = ref('');

function handleDrop(e) {
  isDragging.value = false;
  // setFile เช็คชนิด/ขนาดเองและบอกเหตุผล — เดิมลากไฟล์ที่ไม่ใช่รูปมาแล้วเงียบไปเฉย ๆ
  setFile(e.dataTransfer.files[0]);
}

// ตรงกับกฎของเซิร์ฟเวอร์ (slip_image: image, max:5120) — เช็คตั้งแต่ตอนเลือกไฟล์
// ไม่งั้นลูกค้าจะรอ OCR จนเสร็จแล้วค่อยโดนปฏิเสธตอนกดยืนยัน
const MAX_SLIP_BYTES = 5 * 1024 * 1024;

function setFile(file) {
  if (!file) return;
  if (!file.type?.startsWith('image/')) {
    slipError.value = 'กรุณาแนบสลิปเป็นไฟล์รูปภาพ (JPG หรือ PNG)';
    if (slipInputRef.value) slipInputRef.value.value = '';
    return;
  }
  if (file.size > MAX_SLIP_BYTES) {
    slipError.value = 'ไฟล์สลิปใหญ่เกิน 5MB กรุณาแคปหน้าจอสลิปจากแอปธนาคารแล้วแนบใหม่';
    if (slipInputRef.value) slipInputRef.value.value = '';
    return;
  }
  slipError.value = '';
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

function copyBankAccount() {
  navigator.clipboard.writeText('2301390958');
  swal.success('คัดลอกเลขบัญชีแล้ว', 'กสิกรไทย 230-1-39095-8');
}

// ยอดเงินบาท — เต็มบาทไม่มีทศนิยม มีสตางค์โชว์ไม่เกินสองตำแหน่ง (แบบเดียวกับแอป)
const money = (n) => Number(n || 0).toLocaleString('th-TH', { maximumFractionDigits: 2 });

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
// badge = ป้ายใน public/images/pay (สัญลักษณ์ธนาคารจาก omise/banks-logo ชุดเดียวกับแอป)
const ALL_BANK_APPS = [
  { type: 'KPLUS', label: 'K PLUS', bank: 'กสิกรไทย', badge: 'kbank' },
  { type: 'SCB_EASY', label: 'SCB EASY', bank: 'ไทยพาณิชย์', badge: 'scb' },
  { type: 'KRUNGSRI_APP', label: 'Krungsri', bank: 'กรุงศรีอยุธยา', badge: 'bay' },
  { type: 'BANGKOK_BANK_APP', label: 'Bualuang', bank: 'กรุงเทพ', badge: 'bbl' },
];

// แถบ "เปิดแอปธนาคารโดยตรง" กางอยู่ไหม + แอปที่เพิ่งกด (ให้วงหมุนขึ้นตรงแถวนั้น)
const showBankApps = ref(false);
const pendingBankApp = ref('');
function openBankApp(type) {
  pendingBankApp.value = type;
  createBeamCharge(type);
}
const bankApps = computed(() =>
  ALL_BANK_APPS.filter((app) => gateway.value.methods?.includes(app.type))
);

// หน้าชำระเงินของ Beam — รายการช่องทาง + โลโก้อยู่ใน PaymentCheckoutMethods
const checkoutAvailable = computed(() => useBeam.value && gateway.value.methods?.includes('CHECKOUT'));
// ผ่อนบัตรมีขั้นต่ำ (ตั้งใน Lighthouse) — ยอดไม่ถึง หน้าของ Beam ไม่มีให้เลือก อย่าสัญญา
const installmentMin = computed(() => Number(gateway.value.installment_min_amount || 0));

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
// ตัวเลือกจำนวนงวด (การ์ด "3 × ฿1,200") — ยอดต่องวดและวันปิดยอดของแต่ละตัวเลือกมาจากเซิร์ฟเวอร์
const installmentOptions = computed(() => installmentQuote.value?.options || []);

// ยอดที่ต้องจ่ายตอนนี้ (กล่องสรุปด้านขวา) — สลับเต็ม/มัดจำ/ผ่อนแล้วตัวเลขเลื่อนไปหาค่าใหม่
// ให้เห็นว่ายอดเปลี่ยนไปเท่าไหร่ แทนที่จะกระโดดเงียบ ๆ
const payNowAmount = computed(() => {
  if (paymentType.value === 'installment') return perInstallment.value;
  if (paymentType.value === 'deposit') return depositAmount.value;
  return Number(booking.value?.total_amount || 0);
});
const payNowShown = useTweenedNumber(payNowAmount);

function useTweenedNumber(source, duration = 450) {
  const shown = ref(source.value);
  let frame = 0;
  watch(source, (to) => {
    cancelAnimationFrame(frame);
    const from = shown.value;
    if (from === to || prefersReducedMotion()) {
      shown.value = to;
      return;
    }
    const start = performance.now();
    const step = (now) => {
      const t = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - t, 3);
      // ระหว่างทางปัดเป็นบาท ปลายทางใช้ค่าจริง (ยอดผ่อนอาจมีสตางค์)
      shown.value = t < 1 ? Math.round(from + (to - from) * eased) : to;
      if (t < 1) frame = requestAnimationFrame(step);
    };
    frame = requestAnimationFrame(step);
  });
  onBeforeUnmount(() => cancelAnimationFrame(frame));
  return shown;
}

function prefersReducedMotion() {
  return typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}
const currentPayAmount = computed(() => {
  if (!booking.value) return 0;
  const total = Number(quote.value?.full?.amount ?? booking.value.total_amount);
  // ยอดของรูปแบบที่เลือกต้องมาจากเซิร์ฟเวอร์เสมอ — ถ้าไม่มี (เช่นรูปแบบนั้นใช้ไม่ได้
  // แล้ว) ให้ตกกลับไปเป็นเต็มจำนวน ดีกว่าปล่อยให้ QR ขึ้นยอด 0
  if (paymentType.value === 'installment') return perInstallment.value || total;
  if (paymentType.value === 'deposit') return depositAmount.value || total;
  return total;
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
  transition: opacity 0.3s cubic-bezier(0.2, 0.7, 0.2, 1), transform 0.3s cubic-bezier(0.2, 0.7, 0.2, 1);
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}

/* หัวข้อการ์ดรายการชำระเงิน — ไอคอนบนพื้นเขียวอ่อน + ชื่อ แบบเดียวกับแอป */
.pay-section-title {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 4px;
  font-size: 1.125rem;
  font-weight: 900;
  color: #111827;
}
.pay-section-icon {
  width: 36px;
  height: 36px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  background: var(--color-accent-tint);
  color: var(--color-accent);
}

/* เลขขั้น/เลขงวดในวงกลม — งวดที่จ่ายตอนนี้เป็นวงเขียวทึบ ยอดที่ค้างไว้ทีหลังเป็นอำพัน */
.pay-step {
  width: 24px;
  height: 24px;
  border-radius: 9999px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 900;
  background: #e5e7eb;
  color: #4b5563;
}
.pay-step.is-now {
  background: var(--color-accent);
  color: #fff;
}
.pay-step.is-later {
  background: #fef3c7;
  color: #b45309;
}

/*
 * การเคลื่อนไหวของหน้าชำระเงิน — สั้น นุ่ม และมีหน้าที่: บอกว่าอะไรเพิ่งเปลี่ยน (เลือกแล้ว,
 * สลับช่องทาง, QR ใบใหม่, ยอดเปลี่ยน) ไม่วนซ้ำให้รบกวนตอนกำลังจ่ายเงิน
 * ทุกตัวใช้เส้นโค้งเดียวกัน และปิดหมดเมื่อเครื่องตั้ง "ลดการเคลื่อนไหว"
 */
.pay-rise {
  animation: pay-rise 0.6s cubic-bezier(0.2, 0.7, 0.2, 1) both;
  animation-delay: var(--d, 0ms);
}
@keyframes pay-rise {
  from { opacity: 0; transform: translateY(14px); }
  to { opacity: 1; transform: none; }
}

/* ส่วนที่สลับเข้ามาใหม่ (ช่องทางชำระเงิน, รูปสลิป) */
.pay-swap {
  animation: pay-swap 0.42s cubic-bezier(0.2, 0.7, 0.2, 1) both;
}
@keyframes pay-swap {
  from { opacity: 0; transform: translateY(10px) scale(0.995); }
  to { opacity: 1; transform: none; }
}

/* ติ๊กถูก/ป้ายสำเร็จ — เด้งเข้าเบา ๆ ครั้งเดียว */
.pay-pop {
  animation: pay-pop 0.45s cubic-bezier(0.34, 1.56, 0.64, 1) both;
  animation-delay: var(--d, 0ms);
}
@keyframes pay-pop {
  from { opacity: 0; transform: scale(0.6); }
  to { opacity: 1; transform: none; }
}

/* เส้นขั้นตอน "รายละเอียด → ชำระเงิน" วิ่งเติมตอนเข้าหน้า */
.pay-line {
  transform-origin: left center;
  animation: pay-line 0.9s cubic-bezier(0.65, 0, 0.35, 1) 0.15s both;
}
@keyframes pay-line {
  from { transform: scaleX(0); }
  to { transform: scaleX(1); }
}

/* วงขั้นปัจจุบันมีวงแสงแผ่ออกสองครั้งตอนเข้าหน้า แล้วนิ่ง */
.pay-current {
  position: relative;
}
.pay-current::after {
  content: '';
  position: absolute;
  inset: -4px;
  border-radius: 9999px;
  border: 2px solid #006565;
  opacity: 0;
  animation: pay-halo 1.4s ease-out 0.9s 2;
}
@keyframes pay-halo {
  from { opacity: 0.45; transform: scale(1); }
  to { opacity: 0; transform: scale(1.55); }
}

/* QR ใบใหม่ปรากฏ + แถบแสงสแกนผ่านหนึ่งรอบ ให้รู้ว่าเป็นใบที่เพิ่งออก */
.pay-qr {
  animation: pay-qr 0.5s cubic-bezier(0.2, 0.7, 0.2, 1) both;
}
@keyframes pay-qr {
  from { opacity: 0; transform: scale(0.96); filter: blur(4px); }
  to { opacity: 1; transform: none; filter: none; }
}
.pay-scan {
  position: absolute;
  left: 8px;
  right: 8px;
  top: 8px;
  height: 36%;
  pointer-events: none;
  border-radius: 16px;
  background: linear-gradient(180deg, rgba(13, 148, 136, 0) 0%, rgba(13, 148, 136, 0.14) 85%, rgba(13, 148, 136, 0.55) 100%);
  opacity: 0;
  animation: pay-scan 1.5s cubic-bezier(0.45, 0, 0.55, 1) 0.35s both;
}
@keyframes pay-scan {
  0% { opacity: 0; transform: translateY(-40%); }
  15% { opacity: 1; }
  85% { opacity: 1; }
  100% { opacity: 0; transform: translateY(190%); }
}

/* ปุ่มยืนยัน — แสงวาบผ่านตอนชี้ เฉพาะตอนกดได้ */
.pay-shine {
  position: absolute;
  inset: 0;
  pointer-events: none;
  background: linear-gradient(105deg, transparent 35%, rgba(255, 255, 255, 0.28) 50%, transparent 65%);
  transform: translateX(-120%);
}
button:not(:disabled):hover > .pay-shine {
  transition: transform 0.8s cubic-bezier(0.2, 0.7, 0.2, 1);
  transform: translateX(120%);
}

@media (prefers-reduced-motion: reduce) {
  .pay-rise,
  .pay-swap,
  .pay-pop,
  .pay-line,
  .pay-qr,
  .pay-current::after,
  .pay-scan {
    animation: none !important;
  }
  .pay-scan,
  .pay-shine {
    display: none;
  }
}
</style>
