<template>
  <div class="booking-guide-page font-anuphan bg-[var(--color-sand)] min-h-screen">
    <!-- HERO SECTION -->
    <section class="relative min-h-[300px] flex items-center overflow-hidden -mt-16 bg-[var(--color-primary)]">
      <div class="absolute inset-0">
        <img
          src="/images/landscape.webp"
          alt="How to Book"
          class="w-full h-full object-cover"
        />
        <div class="absolute inset-0 bg-black/30"></div>
      </div>
      <div class="relative z-10 w-full px-6 md:px-8 py-24 md:py-32 text-center flex flex-col items-center">
        <div class="w-16 h-1.5 bg-[var(--color-accent)] mb-6 rounded-full shadow-lg"></div>
        <h1 class="text-4xl md:text-6xl font-black text-white leading-tight mb-4 tracking-tight drop-shadow-md">
          วิธีการจอง
        </h1>
        <p class="text-lg md:text-xl text-white/80 font-bold max-w-2xl mx-auto tracking-wide">
          เพียงไม่กี่ขั้นตอนง่ายๆ คุณก็พร้อมออกไปสัมผัสโลกกว้างกับเรา
        </p>
      </div>
    </section>

    <!-- CONTENT SECTION -->
    <section class="py-16 md:py-24">
      <div class="max-w-4xl mx-auto px-6 md:px-8">
        <div class="bg-white rounded-[2.5rem] p-8 md:p-12 shadow-sm border border-[var(--color-sand-dark)] space-y-12">
          
          <div class="space-y-10">
            <!-- Step 1 -->
            <div class="flex flex-col md:flex-row gap-6 md:gap-10 items-start">
              <div class="w-16 h-16 shrink-0 bg-[var(--color-primary)] text-white rounded-2xl flex items-center justify-center text-3xl font-black shadow-lg shadow-primary/20">1</div>
              <div class="space-y-3">
                <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)]">เลือกทริปที่ต้องการ</h2>
                <p class="text-lg text-[var(--color-text-mid)] leading-relaxed font-medium">
                  เข้าชมหน้ารวมกิจกรรม เลือกทริปดำน้ำ เดินป่า หรือรถตู้พรีเมียมที่ท่านสนใจ อ่านรายละเอียดการเดินทางและรอบวันที่สะดวก
                </p>
              </div>
            </div>

            <!-- Step 2 -->
            <div class="flex flex-col md:flex-row gap-6 md:gap-10 items-start">
              <div class="w-16 h-16 shrink-0 bg-[var(--color-accent)] text-white rounded-2xl flex items-center justify-center text-3xl font-black shadow-lg shadow-accent/20">2</div>
              <div class="space-y-3">
                <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)]">ตรวจสอบรอบและจองที่นั่ง</h2>
                <p class="text-lg text-[var(--color-text-mid)] leading-relaxed font-medium">
                  กดปุ่ม "จองตอนนี้" เลือกวันที่ต้องการ และระบุจำนวนผู้เดินทาง หรือเลือกที่นั่งรถตู้ตามที่ระบบกำหนดไว้
                </p>
              </div>
            </div>

            <!-- Step 3 -->
            <div class="flex flex-col md:flex-row gap-6 md:gap-10 items-start">
              <div class="w-16 h-16 shrink-0 bg-[var(--color-gold)] text-white rounded-2xl flex items-center justify-center text-3xl font-black shadow-lg shadow-gold/20">3</div>
              <div class="space-y-3">
                <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)]">ชำระเงินออนไลน์</h2>
                <p class="text-lg text-[var(--color-text-mid)] leading-relaxed font-medium">
                  ทำตามขั้นตอนการชำระเงินผ่านระบบอัตโนมัติ ซึ่งรองรับการสแกน QR Code หรือบัตรเครดิต สะดวกรวดเร็วและปลอดภัยระดับสากล
                </p>
              </div>
            </div>

            <!-- Step 4 -->
            <div class="flex flex-col md:flex-row gap-6 md:gap-10 items-start">
              <div class="w-16 h-16 shrink-0 bg-teal-500 text-white rounded-2xl flex items-center justify-center text-3xl font-black shadow-lg shadow-teal-500/20">4</div>
              <div class="space-y-3">
                <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)]">รับการยืนยันและออกเดินทาง</h2>
                <p class="text-lg text-[var(--color-text-mid)] leading-relaxed font-medium">
                  เมื่อชำระเงินสำเร็จ ท่านจะได้รับ Voucher ยืนยันทางอีเมลและประวัติการจองในบัญชีสมาชิก เพียงเท่านี้ก็เตรียมชุดสวยๆ แล้วไปลุยกันเลย!
                </p>
              </div>
            </div>
          </div>

          <div class="p-8 bg-blue-50 rounded-[2rem] border border-blue-100 flex flex-col md:flex-row items-center gap-6">
            <div class="w-16 h-16 bg-blue-500 rounded-full flex items-center justify-center text-white shrink-0 shadow-lg">
              <span class="material-symbols-rounded text-[32px]">contact_support</span>
            </div>
            <div class="text-center md:text-left">
              <h3 class="text-xl font-bold text-blue-900 mb-1">ต้องการความช่วยเหลือ?</h3>
              <p class="text-blue-800/70 font-medium">หากติดปัญหาในการจอง สามารถติดต่อสอบถามผ่าน LINE @luilaykhao ได้ตลอด 24 ชม.</p>
            </div>
          </div>

          <div class="pt-8 border-t border-[var(--color-sand-dark)] text-center">
             <router-link to="/trips" class="inline-flex items-center gap-2 px-10 py-4 bg-[var(--color-text-dark)] text-white rounded-full font-bold hover:bg-black transition-all shadow-xl">
              เริ่มจองทริปแรกของคุณ
              <span class="material-symbols-rounded text-[20px]">shopping_cart</span>
            </router-link>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'

onMounted(() => {
  window.scrollTo(0, 0)
})
</script>

<style scoped>
.booking-guide-page {
  animation: fadeIn 0.8s ease-out;
}
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
</style>
