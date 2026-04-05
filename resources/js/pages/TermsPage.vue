<template>
  <div class="terms-page font-anuphan bg-[var(--color-sand)] min-h-screen">
    <!-- HERO SECTION -->
    <section class="relative min-h-[300px] flex items-center overflow-hidden -mt-16 bg-[#0D2B1E]">
      <div class="absolute inset-0">
        <img
          src="/images/landscape.png"
          alt="Terms of Use"
          class="w-full h-full object-cover opacity-60"
        />
        <div class="absolute inset-0 bg-gradient-to-b from-[#0D2B1E]/60 via-[#1A3A2E]/40 to-[#0D2B1E]/90"></div>
      </div>
      <div class="relative z-10 w-full px-6 md:px-8 py-24 md:py-32 text-center">
        <h1 class="text-4xl md:text-6xl font-extrabold text-white leading-tight mb-4 drop-shadow-lg">
          ข้อกำหนดการใช้งาน
        </h1>
        <p class="text-lg md:text-xl text-white/90 font-medium max-w-2xl mx-auto">
          อ่านข้อกำหนดเหล่านี้เพื่อให้การเดินทางของคุณเป็นไปอย่างราบรื่นและปลอดภัย
        </p>
      </div>
    </section>

    <!-- CONTENT SECTION -->
    <section class="py-16 md:py-24">
      <div class="max-w-4xl mx-auto px-6 md:px-8">
        <div class="bg-white rounded-[2.5rem] p-8 md:p-12 shadow-sm border border-[var(--color-sand-dark)] space-y-12">
          
          <div class="space-y-6">
            <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-8 bg-[var(--color-gold)] rounded-full"></span>
              ความเข้าใจเบื้องต้น
            </h2>
            <p class="text-lg text-[var(--color-text-mid)] leading-relaxed">
              ยินดีต้อนรับเข้าสู่ ลุยเลเขา (LuiLayKhao) เมื่อท่านใช้งานแพลตฟอร์มของเราหรือจองทริปผ่านเรา ท่านตกลงที่จะผูกพันตามข้อกำหนดและเงื่อนไขดังต่อไปนี้:
            </p>
          </div>

          <div class="space-y-6">
            <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-8 bg-[var(--color-accent)] rounded-full"></span>
              1. การจองทริปและการยืนยัน
            </h2>
            <ul class="space-y-4">
              <li class="flex gap-4">
                <span class="material-symbols-rounded text-[var(--color-accent)] shrink-0">info</span>
                <span class="text-[var(--color-text-mid)]">การจองจะสมบูรณ์เมื่อท่านชำระเงินตามจำนวนและเวลาที่กำหนดเท่านั้น</span>
              </li>
              <li class="flex gap-4">
                <span class="material-symbols-rounded text-[var(--color-accent)] shrink-0">info</span>
                <span class="text-[var(--color-text-mid)]">ท่านต้องให้ข้อมูลที่เป็นความจริงเพื่อความถูกต้องในการทำประกันภัยการเดินทางและติดต่อประสานงาน</span>
              </li>
              <li class="flex gap-4">
                <span class="material-symbols-rounded text-[var(--color-accent)] shrink-0">info</span>
                <span class="text-[var(--color-text-mid)]">ที่นั่งในรถหรือที่พักจะได้รับการจัดสรรตามลำดับการจองหรือตามที่ระบบระบุไว้</span>
              </li>
            </ul>
          </div>

          <div class="space-y-6">
            <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-8 bg-[var(--color-primary)] rounded-full"></span>
              2. การชำระเงินและความปลอดภัย
            </h2>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              เราใช้ช่องทางการชำระเงินที่ได้มาตรฐานสากลเพื่อความปลอดภัยของข้อมูลทางการเงินของท่าน ท่านตกลงรับผิดชอบต่อค่าใช้จ่ายทั้งหมดที่เกิดจากการจองภายใต้บัญชีของท่าน
            </p>
            <div class="p-6 bg-[var(--color-sand)] rounded-2xl border border-[var(--color-sand-dark)] text-[var(--color-text-dark)] italic">
              "ความปลอดภัยด้านการเงินของคุณคือสิ่งสำคัญ เราเลือกใช้พันธมิตรการดูแลระบบชำระเงินที่เชื่อถือได้เท่านั้น"
            </div>
          </div>

          <div class="space-y-6">
            <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-8 bg-red-400 rounded-full"></span>
              3. นโยบายการยกเลิกและการคืนเงิน
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
              <div class="p-5 border border-red-100 bg-red-50/50 rounded-2xl space-y-2">
                <h4 class="font-bold text-red-800">ยกเลิกล่วงหน้ามากกว่า 30 วัน</h4>
                <p class="text-red-700">คืนเงินเต็มจำนวน (หักค่าธรรมเนียมธุรกรรม)</p>
              </div>
              <div class="p-5 border border-orange-100 bg-orange-50/50 rounded-2xl space-y-2">
                <h4 class="font-bold text-orange-800">ยกเลิกล่วงหน้า 15-29 วัน</h4>
                <p class="text-orange-700">คืนเงิน 50% ของยอดจองทั้งหมด</p>
              </div>
              <div class="p-5 border border-gray-100 bg-gray-50/50 rounded-2xl space-y-2">
                <h4 class="font-bold text-gray-800">ยกเลิกน้อยกว่า 14 วัน</h4>
                <p class="text-gray-700">ขอสงวนสิทธิ์ในการไม่คืนเงินทุกกรณี</p>
              </div>
              <div class="p-5 border border-blue-100 bg-blue-50/50 rounded-2xl space-y-2">
                <h4 class="font-bold text-blue-800">กรณีทริปถูกยกเลิกโดยเรา</h4>
                <p class="text-blue-700">คืนเงินเต็มจำนวน 100%</p>
              </div>
            </div>
            <p class="text-sm text-[var(--color-text-muted)] italic text-center">*นโยบายอาจมีการเปลี่ยนแปลงตามเงื่อนไขเฉพาะของแต่ละทริป</p>
          </div>

          <div class="space-y-6">
            <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-8 bg-[var(--color-accent)] rounded-full"></span>
              4. กฎระเบียบระหว่างการเดินทาง
            </h2>
            <ul class="list-disc pl-6 space-y-3 text-[var(--color-text-mid)]">
              <li>ตรงต่อเวลาตามจุดนัดหมาย หากท่านมาสายเกินเวลาที่กำหนด เราสงวนสิทธิ์ในการออกเดินทางและไม่คืนเงิน</li>
              <li>งดพฤติกรรมที่รบกวนผู้อื่นหรือสิ่งแวดล้อม (Zero Waste Travel)</li>
              <li>ปฏิบัติตามคำแนะนำของเจ้าหน้าที่และไกด์นำเที่ยวเพื่อความปลอดภัย</li>
            </ul>
          </div>

          <div class="space-y-6">
            <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-8 bg-[var(--color-primary)] rounded-full"></span>
              5. ขอบเขตความรับผิดชอบ
            </h2>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              เราทำหน้าที่เป็นเพียงตัวกลางในการจองทริปและความสะดวกต่างๆ เราจะไม่รับผิดชอบต่อความสูญเสีย ความเสียหาย หรือค่าใช้จ่ายที่เกิดจากปัจจัยภายนอกที่ควบคุมไม่ได้ เช่น สภาพอากาศ ภัยธรรมชาติ การนัดหยุดงาน หรืออุบัติเหตุที่เกิดจากการไม่ปฏิบัติตามกฎเกณฑ์
            </p>
          </div>

          <div class="pt-8 border-t border-[var(--color-sand-dark)]">
            <p class="text-[var(--color-text-muted)] text-sm text-center">
              ปรัปปรุงล่าสุดเมื่อ: 4 เมษายน 2569<br>
              สอบถามข้อมูลพิ่มเติม LINE @luilaykhao
            </p>
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
.terms-page {
  animation: fadeIn 0.8s ease-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
</style>
