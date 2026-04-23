<template>
  <!-- Loading -->
  <div v-if="loading" class="flex items-center justify-center min-h-[60vh]">
    <div class="flex flex-col items-center gap-4">
      <div class="w-12 h-12 rounded-full border-4 border-[#b4eae9] border-t-[#006565] animate-spin"></div>
      <p class="text-[#6e7979] font-['Anuphan']">กำลังโหลด...</p>
    </div>
  </div>

  <!-- No booking -->
  <div v-else-if="!booking" class="flex flex-col items-center justify-center min-h-[60vh] text-[#6e7979] font-['Anuphan']">
    <span class="material-symbols-rounded text-6xl mb-4 text-[#bdc9c8]">sentiment_dissatisfied</span>
    <p class="text-lg">ไม่พบข้อมูลการจอง</p>
  </div>

  <!-- Main Content -->
  <div v-else class="font-['Anuphan'] bg-[#f9f9f9] min-h-screen pt-8 pb-24 px-4 md:px-8 lg:px-12">
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
      <div class="bg-red-50 border border-red-100 rounded-3xl p-5 md:p-6 mb-4 flex flex-col md:flex-row items-center justify-between gap-4 shadow-sm">
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center shrink-0">
            <span class="material-symbols-rounded text-red-600 text-2xl animate-pulse">crisis_alert</span>
          </div>
          <div>
            <h3 class="text-red-900 font-bold text-base md:text-lg">กรุณาชำระเงินเพื่อยืนยันสิทธิ์</h3>
            <p class="text-red-700 text-sm">เราจะสำรองที่นั่งให้คุณเป็นเวลา 10 นาที มิฉะนั้นรายการจะถูกยกเลิกโดยอัตโนมัติ</p>
          </div>
        </div>
        <div class="bg-white px-6 py-3 rounded-2xl border border-red-200 shadow-sm">
          <CountdownTimer :seconds="seatsStore.countdownSeconds" />
        </div>
      </div>
    </div>

    <!-- Two-column layout -->
    <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-12 gap-10 items-start">

      <!-- LEFT: Payment Flow -->
      <div class="lg:col-span-8 space-y-8 pb-10">

        <!-- ── Step Instructions ── -->
        <div class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-gray-100">
           <h2 class="text-xl font-bold mb-8 text-gray-900 flex items-center gap-2">
            <span class="material-symbols-rounded text-teal-600">checklist</span>
            ขั้นตอนการชำระเงิน
          </h2>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6 relative">
            <!-- Connector lines (Desktop) -->
            <div class="hidden md:block absolute top-7 left-[15%] right-[15%] h-[2px] bg-gray-100"></div>
            
            <div class="relative flex flex-col items-center text-center gap-3">
              <div class="w-14 h-14 rounded-2xl bg-teal-50 text-teal-600 flex items-center justify-center font-black text-xl border-2 border-teal-100 shadow-sm z-10">1</div>
              <p class="font-bold text-gray-900">สแกน QR เพื่อชำระเงิน</p>
              <p class="text-xs text-gray-500 leading-relaxed px-2">เปิดแอปธนาคารแล้วสแกน QR Code ด้านล่าง</p>
            </div>
            <div class="relative flex flex-col items-center text-center gap-3">
              <div class="w-14 h-14 rounded-2xl bg-teal-50 text-teal-600 flex items-center justify-center font-black text-xl border-2 border-teal-100 shadow-sm z-10">2</div>
              <p class="font-bold text-gray-900">อัปโหลดสลิป</p>
              <p class="text-xs text-gray-500 leading-relaxed px-2">แนบหลักฐานการโอนเงินเพื่อตรวจสอบความถูกต้อง</p>
            </div>
            <div class="relative flex flex-col items-center text-center gap-3">
              <div class="w-14 h-14 rounded-2xl bg-teal-50 text-teal-600 flex items-center justify-center font-black text-xl border-2 border-teal-100 shadow-sm z-10">3</div>
              <p class="font-bold text-gray-900">กดยืนยันการชำระเงิน</p>
              <p class="text-xs text-gray-500 leading-relaxed px-2">เสร็จสิ้น! รอเจ้าหน้าที่ตรวจสอบใน 10 นาที</p>
            </div>
          </div>
        </div>

        <!-- ── Payment Type Selection (show only if installment available) ── -->
        <section v-if="installmentAvailable" class="bg-white rounded-3xl shadow-sm p-8 border border-gray-100">
          <h2 class="text-lg font-bold mb-5 text-gray-900 flex items-center gap-2">
            <span class="material-symbols-rounded text-amber-500">credit_card</span>
            เลือกรูปแบบการชำระเงิน
          </h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <!-- Full Payment -->
            <button @click="paymentType = 'full'"
              class="group flex flex-col gap-2 p-5 border-2 rounded-2xl transition-all text-left relative overflow-hidden"
              :class="paymentType === 'full'
                ? 'border-teal-600 bg-teal-50/30'
                : 'border-gray-100 hover:border-teal-100 hover:bg-gray-50'">
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-[24px] group-hover:scale-110 transition-transform" :class="paymentType === 'full' ? 'text-teal-600' : 'text-gray-400'">payments</span>
                <span class="font-bold text-base" :class="paymentType === 'full' ? 'text-teal-900' : 'text-gray-700'">ชำระเต็มจำนวน</span>
                <div v-if="paymentType === 'full'" class="ml-auto w-6 h-6 rounded-full bg-teal-600 flex items-center justify-center shadow-md">
                  <span class="material-symbols-rounded text-white text-[16px]">check</span>
                </div>
              </div>
              <p class="text-sm text-gray-500">ชำระทั้งหมด <span class="font-bold text-gray-900">฿{{ Number(booking.total_amount).toLocaleString() }}</span> ในครั้งเดียว</p>
            </button>

            <!-- Installment Payment -->
            <button @click="paymentType = 'installment'"
              class="group flex flex-col gap-2 p-5 border-2 rounded-2xl transition-all text-left relative overflow-hidden"
              :class="paymentType === 'installment'
                ? 'border-amber-500 bg-amber-50/30'
                : 'border-gray-100 hover:border-amber-100 hover:bg-gray-50'">
              <div class="flex items-center gap-2">
                <span class="material-symbols-rounded text-[24px] group-hover:scale-110 transition-transform" :class="paymentType === 'installment' ? 'text-amber-600' : 'text-gray-400'">calendar_month</span>
                <span class="font-bold text-base" :class="paymentType === 'installment' ? 'text-amber-900' : 'text-gray-700'">ผ่อนชำระ {{ installmentCount }} งวด</span>
                <div v-if="paymentType === 'installment'" class="ml-auto w-6 h-6 rounded-full bg-amber-500 flex items-center justify-center shadow-md">
                  <span class="material-symbols-rounded text-white text-[16px]">check</span>
                </div>
              </div>
              <p class="text-sm text-gray-500">งวดละ <span class="font-bold text-amber-600">฿{{ perInstallment.toLocaleString() }}</span> · ทุก {{ installmentIntervalDays }} วัน</p>
            </button>
          </div>

          <!-- Installment Schedule Table -->
          <div v-if="paymentType === 'installment'" class="mt-8">
             <div class="flex items-center justify-between mb-4">
                <h3 class="font-bold text-gray-900 tracking-tight">ตารางการผ่อนชำระ</h3>
                <span class="text-[11px] font-black text-amber-600 bg-amber-100 px-3 py-1 rounded-full uppercase tracking-tighter">ยอดรวมคงเดิม ไม่มีดอกเบี้ย</span>
             </div>
            <div class="overflow-hidden rounded-2xl border border-gray-100 shadow-sm">
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
                        class="text-[10px] font-black px-3 py-1.5 rounded-full bg-teal-600 text-white shadow-sm uppercase tracking-tighter">
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
        <section class="bg-white rounded-3xl shadow-sm p-8 border border-gray-100">
          <h2 class="text-xl font-extrabold mb-8 text-gray-900 flex items-center gap-2">
            <span class="material-symbols-rounded text-teal-600">account_balance_wallet</span>
            เลือกช่องทางชำระเงิน
          </h2>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-10">
            <button @click="paymentMethod = 'promptpay'"
              class="group flex flex-col items-center justify-center gap-3 p-6 border-2 rounded-3xl transition-all h-full shadow-sm"
              :class="paymentMethod === 'promptpay'
                ? 'border-teal-600 bg-teal-50/30'
                : 'border-gray-50 bg-gray-50/50 hover:bg-gray-100 text-gray-500'">
              <div class="bg-white p-4 rounded-2xl shadow-sm border border-gray-100 group-hover:scale-105 transition-transform">
                <img src="/images/qr_promptpay.webp" alt="พร้อมเพย์" class="h-12 w-auto object-contain" />
              </div>
              <div class="text-center">
                <p class="font-black text-gray-900 tracking-tight">QR PromptPay</p>
                <p class="text-[11px] text-gray-500 font-medium tracking-tight">ชำระผ่าน Mobile Banking ได้ทันที</p>
              </div>
            </button>
            
            <button @click="paymentMethod = 'mobile_banking'"
              class="group flex flex-col items-center justify-center gap-3 p-6 border-2 rounded-3xl transition-all h-full shadow-sm"
              :class="paymentMethod === 'mobile_banking'
                ? 'border-teal-600 bg-teal-50/30'
                : 'border-gray-50 bg-gray-50/50 hover:bg-gray-100 text-gray-500'">
              <div class="bg-white p-4 rounded-2xl shadow-sm border border-gray-100 group-hover:scale-105 transition-transform">
                <img src="/images/pay_bank.webp" alt="โมบายแบงก์กิ้ง" class="h-12 w-auto object-contain" />
              </div>
              <div class="text-center">
                <p class="font-black text-gray-900 tracking-tight">โอนผ่านบัญชีธนาคาร</p>
                <p class="text-[11px] text-gray-500 font-medium tracking-tight">แนบสลิปผ่านทางหน้านี้</p>
              </div>
            </button>
          </div>

          <!-- PromptPay QR -->
          <div v-if="paymentMethod === 'promptpay'" class="flex flex-col items-center gap-5 py-10 bg-gray-50/50 rounded-3xl border border-gray-100">
             <!-- Thai QR Logo Header -->
             <div class="bg-white px-6 py-3 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-center animate-in fade-in zoom-in-95 duration-500">
               <img src="/images/Thai_QR_Payment_Logo-01.jpg" alt="Thai QR Payment" class="h-10 w-auto object-contain" />
             </div>

             <div class="text-center space-y-1">
                <p class="text-base font-bold text-gray-900">เปิดแอปธนาคารแล้วสแกน QR นี้</p>
                <p class="text-xs text-gray-500 px-4">ระบบจะคำนวณยอดชำระเบื้องต้นให้โดยอัตโนมัติ</p>
             </div>

            <div class="relative group">
               <div class="absolute -inset-4 bg-teal-600/5 rounded-[2.5rem] blur-xl opacity-0 group-hover:opacity-100 transition-opacity"></div>
               <div class="relative p-2 bg-white rounded-3xl shadow-xl border border-teal-100 overflow-hidden">
                <canvas ref="qrCanvas" class="block rounded-2xl w-full max-w-[320px] h-auto mx-auto shadow-sm"></canvas>
                <div v-if="!qrGenerated" class="absolute inset-0 flex items-center justify-center bg-white/80 rounded-3xl">
                  <div class="w-10 h-10 rounded-full border-4 border-teal-100 border-t-teal-600 animate-spin"></div>
                </div>
              </div>
            </div>

            <div class="flex flex-col items-center gap-4 w-full px-6 mt-6">
                <!-- Save QR Button moved up -->
                <div class="flex items-center gap-3">
                  <button v-if="qrGenerated" @click="saveQR"
                    class="flex items-center gap-2.5 px-6 py-3 bg-teal-600 text-white text-sm font-black rounded-2xl hover:bg-teal-700 active:scale-95 transition-all shadow-lg shadow-teal-600/20">
                    <span class="material-symbols-rounded text-[18px]">download</span> บันทึก QR Code
                  </button>
                </div>

                <!-- Payment Details -->
                <div class="flex items-center justify-between w-full max-w-xs bg-white p-3 rounded-2xl border border-gray-100 shadow-sm">
                   <div class="flex flex-col">
                      <span class="text-[10px] font-black text-gray-400 uppercase tracking-widest leading-none">ยอดเงินที่ต้องชำระ</span>
                      <span class="text-lg font-black text-teal-600">฿{{ (paymentType === 'installment' ? perInstallment : booking.total_amount).toLocaleString() }}</span>
                   </div>
                    <button @click="copyAmount" class="flex items-center gap-1.5 px-3 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 text-xs font-bold rounded-xl transition-colors active:scale-95">
                      <span class="material-symbols-rounded text-base">content_copy</span>
                      คัดลอกยอด
                    </button>
                </div>
            </div>
             
             <div class="flex items-center gap-2 py-2 px-4 rounded-full bg-white border border-gray-100 shadow-sm">
                <span class="material-symbols-rounded text-teal-600 text-sm" style="font-variation-settings:'FILL' 1">verified_user</span>
                <p class="text-[11px] text-gray-500 font-bold">e-Wallet: <span class="text-gray-900">004-99923936-2071</span></p>
             </div>
          </div>

          <!-- Bank Transfer info -->
          <div v-else class="bg-teal-50/50 rounded-3xl p-6 space-y-5 border border-teal-100 shadow-inner">
            <p class="text-sm font-black text-teal-900 flex items-center gap-2">
              <span class="material-symbols-rounded text-teal-600 text-[20px]">account_balance</span>
              ข้อมูลบัญชีธนาคาร
            </p>
            <div class="space-y-3">
              <div class="flex items-center justify-between p-4 bg-white rounded-2xl border border-teal-100/50 shadow-sm">
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
              <div class="flex items-center justify-between p-4 bg-white rounded-2xl border border-teal-100/50 shadow-sm relative group">
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
               <div class="flex items-center justify-between p-4 bg-white rounded-2xl border border-teal-600/30 shadow-md relative group">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-teal-50 flex items-center justify-center shrink-0">
                      <span class="material-symbols-rounded text-teal-600 text-xl">numbers</span>
                    </div>
                    <div>
                      <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest leading-none">เลขที่บัญชี</p>
                      <p class="text-lg font-black text-gray-900 tracking-wider">062-6-12600-6</p>
                    </div>
                  </div>
                  <button @click="copyAccount" class="p-2.5 rounded-xl bg-teal-50 text-teal-600 hover:bg-teal-100 transition-colors shadow-sm active:scale-90">
                    <span class="material-symbols-rounded text-xl">content_copy</span>
                  </button>
              </div>
            </div>
            
            <div class="bg-amber-50 p-4 rounded-2xl border border-amber-100 flex items-start gap-3">
               <span class="material-symbols-rounded text-amber-500 text-xl font-bold">info</span>
               <p class="text-xs text-amber-800 font-medium leading-relaxed">
                  กรุณาโอนยอด <strong class="text-amber-900">฿{{ (paymentType === 'installment' ? perInstallment : booking.total_amount).toLocaleString() }}</strong> ให้ครบถ้วน แล้วระบุวันและเวลาโอนตามจริงในสลิป
               </p>
            </div>
          </div>

          <!-- Divider -->
          <div class="flex items-center gap-4 my-10">
            <div class="flex-1 h-[2px] bg-gray-50"></div>
            <span class="text-[11px] text-gray-400 font-black uppercase tracking-[0.2em]">หลักฐานการโอนเงิน</span>
            <div class="flex-1 h-[2px] bg-gray-50"></div>
          </div>

          <!-- Slip Upload -->
          <div class="space-y-6">
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
              class="group relative flex flex-col items-center justify-center gap-4 border-3 border-dashed rounded-[2.5rem] py-12 px-6 cursor-pointer transition-all duration-500 shadow-sm"
              :class="[
                isDragging ? 'border-teal-600 bg-teal-50 scale-[0.99]' : 'border-gray-200 bg-gray-50/50 hover:border-teal-400 hover:bg-teal-50/20',
                slipPreview ? 'border-none p-0 overflow-hidden bg-transparent' : ''
              ]">
              
              <template v-if="!slipPreview">
                <div class="w-20 h-20 rounded-[2rem] bg-white text-teal-500 flex items-center justify-center shadow-lg border border-gray-100 group-hover:scale-110 transition-transform duration-500">
                  <span class="material-symbols-rounded text-4xl">cloud_upload</span>
                </div>
                <div class="text-center space-y-1">
                  <p class="text-base font-black text-gray-900">ลากไฟล์มาวาง หรือคลิกเพื่ออัปโหลด</p>
                  <p class="text-[11px] text-gray-400 font-bold uppercase tracking-widest">JPG, PNG, PDF ขนาดไม่เกิน 5MB</p>
                </div>
              </template>

              <template v-else>
                 <div class="relative w-full max-h-[400px] group/preview">
                    <img :src="slipPreview" alt="slip" class="w-full h-full object-contain rounded-[2rem] bg-gray-100 shadow-inner" />
                    <div class="absolute inset-0 bg-black/40 opacity-0 group-hover/preview:opacity-100 transition-opacity rounded-[2rem] flex items-center justify-center">
                       <p class="text-white font-bold text-sm bg-white/20 backdrop-blur-md px-4 py-2 rounded-full border border-white/30">คลิกเพื่อเปลี่ยนรูป</p>
                    </div>
                    <button @click.stop="removeSlip"
                      class="absolute top-4 right-4 w-10 h-10 bg-white/90 backdrop-blur-md text-red-600 rounded-full flex items-center justify-center shadow-xl hover:bg-red-600 hover:text-white transition-all z-20">
                      <span class="material-symbols-rounded text-[22px]">delete</span>
                    </button>
                    <!-- Success indicator -->
                    <div class="absolute bottom-4 left-4 bg-teal-600 text-white px-4 py-2 rounded-full text-xs font-black flex items-center gap-2 shadow-lg shadow-teal-600/30">
                       <span class="material-symbols-rounded text-[18px]">check_circle</span>
                       อัปโหลดสำเร็จ
                    </div>
                 </div>
              </template>
            </div>
            
            <input ref="slipInputRef" type="file" accept="image/*" required class="hidden" @change="onSlipChange" />

            <!-- Datetime Inputs with Premium Feel -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 bg-gray-50/50 p-6 rounded-3xl border border-gray-100">
              <div class="space-y-2">
                <label class="block text-[11px] font-black text-gray-400 uppercase tracking-widest ml-1">วันที่โอน (ตามสลิป)</label>
                <div class="relative">
                   <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-lg">calendar_today</span>
                   <input v-model="transferDate" type="date" required
                    class="w-full pl-12 pr-4 py-3.5 rounded-2xl border border-gray-100 bg-white shadow-sm text-sm font-bold text-gray-900 focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/5 transition-all" />
                </div>
              </div>
              <div class="space-y-2">
                <label class="block text-[11px] font-black text-gray-400 uppercase tracking-widest ml-1">เวลาที่โอน (ตามสลิป)</label>
                <div class="relative">
                   <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 text-lg">schedule</span>
                   <input v-model="transferTime" type="time" required
                    class="w-full pl-12 pr-4 py-3.5 rounded-2xl border border-gray-100 bg-white shadow-sm text-sm font-bold text-gray-900 focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/5 transition-all" />
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- Trust Badges & Support -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
           <div class="flex items-center gap-4 p-6 bg-white rounded-3xl border border-gray-100 shadow-sm">
             <div class="w-12 h-12 rounded-2xl bg-teal-50 text-teal-600 flex items-center justify-center shrink-0 shadow-inner">
               <span class="material-symbols-rounded text-2xl" style="font-variation-settings:'FILL' 1">verified</span>
             </div>
             <div>
                <p class="text-sm font-bold text-gray-900">ความปลอดภัย 100%</p>
                <p class="text-xs text-gray-500">ข้อมูลของคุณได้รับการเข้ารหัส SSL Encryption</p>
             </div>
           </div>
           <div class="flex items-center gap-4 p-6 bg-white rounded-3xl border border-gray-100 shadow-sm">
             <div class="w-12 h-12 rounded-2xl bg-teal-50 text-teal-600 flex items-center justify-center shrink-0 shadow-inner">
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
        <div class="bg-white rounded-[2.5rem] shadow-[0_20px_50px_rgba(0,0,0,0.05)] overflow-hidden border border-gray-100 transition-all duration-300 hover:shadow-[0_30px_70px_rgba(0,0,0,0.1)]">

          <!-- Trip Premium Header -->
          <div class="h-56 relative overflow-hidden bg-gray-100">
            <img v-if="booking.schedule?.trip?.cover_image || booking.schedule?.trip?.thumbnail_url"
              :src="booking.schedule.trip.cover_image || booking.schedule.trip.thumbnail_url"
              :alt="booking.schedule?.trip?.title"
              class="w-full h-full object-cover transition-transform duration-700 hover:scale-110" />
            
            <div class="absolute inset-0 bg-gradient-to-t from-gray-900 via-gray-900/20 to-transparent"></div>
            
            <div class="absolute top-4 left-4">
               <span class="bg-white/20 backdrop-blur-md text-white text-[10px] font-black px-3 py-1.5 rounded-full uppercase tracking-widest border border-white/30">
                #{{ booking.booking_ref }}
              </span>
            </div>

            <div class="absolute bottom-5 left-6 right-6">
               <p class="text-white text-lg font-black leading-tight drop-shadow-md mb-2">{{ booking.schedule?.trip?.title }}</p>
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
                 <div class="w-10 h-10 rounded-xl bg-white text-teal-600 flex items-center justify-center shadow-sm shrink-0">
                    <span class="material-symbols-rounded text-xl">airline_seat_recline_extra</span>
                 </div>
                 <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest leading-none">หมายเลขที่นั่ง</p>
                    <p class="text-sm font-bold text-gray-900">{{ booking.seats.map(s => s.seat_id).join(', ') }}</p>
                 </div>
               </div>
               
               <div class="flex items-center gap-3 p-4 bg-gray-50 rounded-2xl border border-gray-100 animate-in fade-in slide-in-from-bottom-2 duration-500">
                 <div class="w-10 h-10 rounded-xl bg-white text-amber-600 flex items-center justify-center shadow-sm shrink-0">
                    <span class="material-symbols-rounded text-xl">location_on</span>
                 </div>
                 <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest leading-none">จุดรับเดินทาง</p>
                    <p class="text-sm font-bold text-gray-900 truncate max-w-[180px]">{{ booking.pickup_region || 'ระบุก่อนเดินทาง' }}</p>
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
                 <div class="flex justify-between items-end bg-amber-50 rounded-2xl p-4 border border-amber-100 shadow-inner">
                    <span class="text-xs font-bold text-amber-700">ชำระงวดแรกตอนนี้</span>
                    <div class="text-right">
                       <span class="text-2xl font-black text-amber-600 leading-none">฿{{ perInstallment.toLocaleString() }}</span>
                       <p class="text-[10px] font-bold text-amber-500 leading-none mt-1">จากทั้งหมด {{ installmentCount }} งวด</p>
                    </div>
                 </div>
               </template>
               
               <template v-else>
                 <div class="flex justify-between items-end bg-teal-50 rounded-2xl p-5 border border-teal-100 shadow-inner">
                    <span class="text-sm font-black text-teal-900 uppercase tracking-tight">ยอดชำระสุทธิ</span>
                    <span class="text-3xl font-black text-teal-600 leading-none">฿{{ Number(booking.total_amount).toLocaleString() }}</span>
                 </div>
               </template>
            </div>

            <!-- Main CTA Button -->
            <div class="space-y-4">
              <button @click="processPayment" 
                :disabled="paying || !slipFile"
                class="group w-full py-5 rounded-2xl font-black text-base flex flex-col items-center justify-center gap-1 transition-all duration-500 overflow-hidden relative shadow-xl disabled:shadow-none disabled:bg-gray-100 disabled:text-gray-400 disabled:cursor-not-allowed"
                :class="[
                  paymentType === 'installment'
                    ? 'bg-amber-600 text-white hover:bg-amber-700 shadow-amber-600/30'
                    : 'bg-gray-900 text-white hover:bg-black shadow-gray-900/30'
                ]">
                <!-- Loading State overlay -->
                <div v-if="paying" class="absolute inset-0 bg-inherit flex items-center justify-center z-10">
                   <div class="w-6 h-6 rounded-full border-2 border-white/30 border-t-white animate-spin"></div>
                </div>

                <div class="flex items-center gap-2.5 transition-transform group-hover:scale-105" :class="paying ? 'opacity-0' : 'opacity-100'">
                  <span class="material-symbols-rounded text-xl" style="font-variation-settings:'FILL' 1">verified_user</span>
                  <span>ยืนยันและส่งหลักฐานการชำระเงิน</span>
                </div>
                <div class="text-[10px] opacity-70 tracking-widest uppercase font-bold" :class="paying ? 'opacity-0' : 'opacity-70'">
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
                    <span class="material-symbols-rounded text-teal-600 text-base" style="font-variation-settings:'FILL' 1">av_timer</span>
                  </div>
                  <p class="text-xs text-gray-500 font-medium">เจ้าหน้าที่จะตรวจสอบยอดโอนและยืนยันการจอง ภายใน 10-15 นาที</p>
               </div>
            </div>
          </div>
        </div>

        <!-- Sticky Mobile Button (Only visible on mobile via class) -->
        <div class="lg:hidden fixed bottom-0 left-0 right-0 p-4 bg-white/80 backdrop-blur-xl border-t border-gray-100 z-[100] shadow-[0_-10px_30px_rgba(0,0,0,0.05)] translate-y-0 transition-transform duration-500"
          :class="!loading && booking ? 'translate-y-0' : 'translate-y-full'">
            <button @click="processPayment" 
              :disabled="paying || !slipFile"
              class="w-full py-4 rounded-2xl bg-gray-900 text-white font-black shadow-lg shadow-gray-900/30 flex items-center justify-center gap-2 active:scale-95 transition-all text-sm disabled:bg-gray-100 disabled:text-gray-400">
              <template v-if="!paying">
                <span>ยืนยันการชำระ ฿{{ (paymentType === 'installment' ? perInstallment : booking.total_amount).toLocaleString() }}</span>
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
import { useSwal } from '../lib/swal';

const route = useRoute();
const router = useRouter();
const bookingStore = useBookingStore();
const seatsStore = useSeatsStore();
const swal = useSwal();

const PAYMENT_TIMEOUT_SECONDS = 10 * 60;

const booking = ref(null);
const loading = ref(true);
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
}

function copyAmount() {
  const amount = paymentType.value === 'installment' ? perInstallment.value : booking.value.total_amount;
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

// ── Installment helpers ──────────────────────────────────────
const installmentAvailable = computed(() =>
  !!booking.value?.schedule?.installment_enabled
);
const installmentCount = computed(() =>
  booking.value?.schedule?.installment_count ?? 2
);
const installmentIntervalDays = computed(() =>
  booking.value?.schedule?.installment_interval_days ?? 30
);
const perInstallment = computed(() => {
  if (!booking.value) return 0;
  const total = parseFloat(booking.value.total_amount);
  return Math.round((total / installmentCount.value) * 100) / 100;
});
const installmentSchedule = computed(() => {
  if (!booking.value) return [];
  const total = parseFloat(booking.value.total_amount);
  const n = installmentCount.value;
  const interval = installmentIntervalDays.value;
  const per = Math.round((total / n) * 100) / 100;
  const rows = [];
  const now = new Date();
  for (let i = 1; i <= n; i++) {
    const dueDate = new Date(now);
    dueDate.setDate(dueDate.getDate() + (i - 1) * interval);
    const amount = i === n ? Math.round((total - per * (n - 1)) * 100) / 100 : per;
    rows.push({ no: i, dueDate: dueDate.toISOString().split('T')[0], amount });
  }
  return rows;
});

// ── QR regenerates when paymentType or paymentMethod changes ─
watch([paymentType, paymentMethod], ([, method]) => {
  if (method === 'promptpay') nextTick(generateQR);
});

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
  
  const amount = paymentType.value === 'installment'
    ? perInstallment.value
    : parseFloat(booking.value.total_amount);
    
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
  slipFile.value = file;
  const reader = new FileReader();
  reader.onload = (ev) => { slipPreview.value = ev.target.result; };
  reader.readAsDataURL(file);
}

function removeSlip() {
  slipFile.value = null;
  slipPreview.value = null;
  if (slipInputRef.value) slipInputRef.value.value = '';
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' });
}

function initPaymentCountdown() {
  if (!booking.value || booking.value.status !== 'pending') {
    seatsStore.clearSelection();
    return;
  }

  const createdAtMs = booking.value.created_at ? new Date(booking.value.created_at).getTime() : Date.now();
  const baseTimeMs = Number.isFinite(createdAtMs) ? createdAtMs : Date.now();

  seatsStore.lockExpiry = new Date(baseTimeMs + PAYMENT_TIMEOUT_SECONDS * 1000).toISOString();
  seatsStore.setActiveBookingInfo({
    tripTitle: booking.value.schedule?.trip?.title || 'กิจกรรม',
    scheduleId: booking.value.schedule_id ?? booking.value.schedule?.id,
    bookingRef: booking.value.booking_ref,
    step: 'payment',
    startedAt: baseTimeMs,
  });
  seatsStore.startCountdown();
}

async function handlePaymentExpiry() {
  if (autoCancelling.value) return;
  autoCancelling.value = true;

  try {
    if (booking.value?.booking_ref && booking.value?.status === 'pending') {
      await bookingStore.cancelBooking(
        booking.value.booking_ref,
        'หมดเวลาชำระเงินเกิน 10 นาที ระบบยกเลิกการจองอัตโนมัติ'
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
    'ครบกำหนด 10 นาที ระบบได้ยกเลิกการจองและคืนที่นั่งเรียบร้อยแล้ว กรุณาทำรายการใหม่อีกครั้ง'
  );
  router.push('/trips');
}

async function processPayment() {
  if (seatsStore.countdownSeconds <= 0) {
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
  if (!transferDate.value) {
    paymentError.value = 'กรุณาระบุวันที่โอนเงิน';
    return;
  }
  if (!transferTime.value) {
    paymentError.value = 'กรุณาระบุเวลาที่โอนเงิน';
    return;
  }
  paying.value = true;
  paymentError.value = '';
  try {
    const fd = new FormData();
    fd.append('booking_ref', booking.value.booking_ref);
    fd.append('payment_type', paymentType.value);
    fd.append('payment_method', paymentMethod.value);
    fd.append('amount', paymentType.value === 'installment'
      ? perInstallment.value
      : parseFloat(booking.value.total_amount));
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
    booking.value = await bookingStore.fetchBooking(route.params.bookingRef);
    initPaymentCountdown();
  } catch (e) {
    console.error(e);
  } finally {
    loading.value = false;
  }
  if (paymentMethod.value === 'promptpay') {
    await nextTick();
    generateQR();
  }
});

onBeforeUnmount(() => {
  seatsStore.offExpire(handlePaymentExpiry);
});
</script>
