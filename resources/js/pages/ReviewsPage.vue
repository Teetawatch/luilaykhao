<template>
  <div class="reviews-page font-anuphan bg-[var(--color-sand)] min-h-screen">
    <!-- HERO SECTION -->
    <section class="relative min-h-[300px] flex items-center overflow-hidden -mt-16 bg-[var(--color-primary)]">
      <div class="absolute inset-0">
        <img
          src="/images/landscape.png"
          alt="Customer Reviews"
          class="w-full h-full object-cover"
        />
        <div class="absolute inset-0 bg-black/30"></div>
      </div>
      <div class="relative z-10 w-full px-6 md:px-8 py-24 md:py-32 text-center flex flex-col items-center">
        <div class="w-16 h-1.5 bg-[var(--color-accent)] mb-6 rounded-full shadow-lg"></div>
        <h1 class="text-4xl md:text-6xl font-black text-white leading-tight mb-4 tracking-tight drop-shadow-md">
          รีวิวจากลูกค้า
        </h1>
        <p class="text-lg md:text-xl text-white/80 font-bold max-w-2xl mx-auto tracking-wide">
          ความประทับใจจากเหล่านักเดินทางที่ร่วมสร้างประสบการณ์กับลุยเลเขา
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
              เสียงสะท้อนจากหัวใจ
            </h2>
            <p class="text-lg text-[var(--color-text-mid)] leading-relaxed">
              เราขอขอบคุณลูกค้าทุกท่านที่เลือกเดินทางไปกับเรา ทุกคำติชมคือพลังใจและการพัฒนาส่วนงานบริการของเราให้ดียิ่งขึ้น
            </p>
          </div>

          <!-- Featured Review -->
          <div class="p-8 bg-[var(--color-sand)] rounded-[2rem] border border-[var(--color-sand-dark)] space-y-4">
            <div class="flex items-center gap-1 text-[var(--color-gold)]">
              <span v-for="i in 5" :key="i" class="material-symbols-rounded" style="font-variation-settings:'FILL' 1">star</span>
            </div>
            <p class="text-xl font-bold text-[var(--color-text-dark)] italic leading-relaxed">
              "ประทับใจมากค่ะ ทริปดำน้ำจัดระบบดีมาก ไกด์ดูแลทั่วถึง รถตู้ใหม่สะอาด พี่คนขับขับสุภาพสุดๆ ไว้จะกลับมาใช้บริการอีกแน่นอนค่ะ"
            </p>
            <div class="flex items-center gap-4 pt-4 border-t border-[var(--color-sand-dark)]">
              <div class="w-12 h-12 rounded-full bg-[var(--color-primary)] flex items-center justify-center text-white font-bold text-lg shadow-md">K</div>
              <div>
                <div class="font-bold text-[var(--color-text-dark)]">คุณธัญญารัตน์</div>
                <div class="text-sm text-[var(--color-text-muted)] font-medium">ทริปเกาะหลีเป๊ะ 3 วัน 2 คืน</div>
              </div>
            </div>
          </div>

          <!-- Multiple Reviews Grid style -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div v-for="(review, index) in reviews" :key="index" class="p-6 border border-[var(--color-sand-dark)] rounded-3xl space-y-4 hover:shadow-md transition-shadow">
               <div class="flex items-center gap-1 text-[var(--color-gold)]">
                <span v-for="i in 5" :key="i" class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 1">star</span>
              </div>
              <p class="text-[var(--color-text-mid)] font-medium leading-relaxed">
                {{ review.text }}
              </p>
              <div class="flex items-center gap-3 pt-2">
                <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center font-bold text-[var(--color-text-dark)] text-xs shadow-sm">{{ review.name.charAt(0) }}</div>
                <div class="text-[13px] font-bold text-[var(--color-text-dark)]">{{ review.name }}</div>
              </div>
            </div>
          </div>

          <div class="pt-8 border-t border-[var(--color-sand-dark)] text-center">
            <router-link to="/trips" class="inline-flex items-center gap-2 px-8 py-3.5 bg-[var(--color-primary)] text-white rounded-full font-bold hover:bg-[var(--color-primary-mid)] transition-all shadow-lg shadow-primary/20">
              จองทริปและสร้างรีวิวของคุณ
              <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
            </router-link>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'

const reviews = [
  { name: 'คุณวิชัย', text: 'รถตู้พรีเมียมจริงตามที่แจ้งครับ เบาะใหญ่นั่งสบาย แอร์เย็นจัด เดินทางไกลไม่เหนื่อยเลย' },
  { name: 'คุณเมษา', text: 'ไปคนเดียวก็ไม่เหงาค่ะ เพื่อนร่วมทริปน่ารัก ทีมงานดูแลดีมาก แนะนำเลยสำหรับสาวๆ ที่ชอบเที่ยว' },
  { name: 'คุณนพพล', text: 'จัดสรรเวลาในทริปได้ดีมากครับ ไม่รีบจนเกินไป มีเวลาให้ถ่ายรูปสวยๆ เยอะมาก' },
  { name: 'คุณกิตติรัฐ', text: 'การชำระเงินสะดวกและปลอดภัย ทีมงานตอบไลน์ไวมาก ช่วยไขข้อข้องใจได้ชัดเจนครับ' }
];

onMounted(() => {
  window.scrollTo(0, 0)
})
</script>

<style scoped>
.reviews-page {
  animation: fadeIn 0.8s ease-out;
}
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
</style>
