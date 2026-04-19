<template>
  <div class="faq-page font-anuphan bg-[var(--color-sand)] min-h-screen">
    <!-- HERO SECTION -->
    <section class="relative min-h-[300px] flex items-center overflow-hidden -mt-16 bg-[var(--color-primary)]">
      <div class="absolute inset-0">
        <img
          src="/images/landscape.png"
          alt="FAQ"
          class="w-full h-full object-cover"
        />
        <div class="absolute inset-0 bg-black/30"></div>
      </div>
      <div class="relative z-10 w-full px-6 md:px-8 py-24 md:py-32 text-center flex flex-col items-center">
        <div class="w-16 h-1.5 bg-[var(--color-accent)] mb-6 rounded-full shadow-lg"></div>
        <h1 class="text-4xl md:text-6xl font-black text-white leading-tight mb-4 tracking-tight drop-shadow-md">
          คำถามที่พบบ่อย
        </h1>
        <p class="text-lg md:text-xl text-white/80 font-bold max-w-2xl mx-auto tracking-wide">
          รวบรวมคำตอบสำหรับข้อสงสัย เพื่อให้คุณเตรียมพร้อมสำหรับทริปสุดพิเศษ
        </p>
      </div>
    </section>

    <!-- CONTENT SECTION -->
    <section class="py-16 md:py-24">
      <div class="max-w-4xl mx-auto px-6 md:px-8">
        <div class="bg-white rounded-[2.5rem] p-8 md:p-12 shadow-sm border border-[var(--color-sand-dark)] space-y-12">
          
          <div v-for="(group, gIndex) in faqGroups" :key="gIndex" class="space-y-6">
            <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-8 rounded-full" :class="group.color"></span>
              {{ group.title }}
            </h2>
            
            <div class="space-y-4">
              <div v-for="(item, iIndex) in group.questions" :key="iIndex" class="p-6 border border-[var(--color-sand-dark)] rounded-3xl hover:border-primary/30 transition-all group">
                <h3 class="text-lg font-bold text-[var(--color-text-dark)] mb-2 flex items-start gap-3">
                  <span class="text-primary font-black">Q:</span>
                  {{ item.q }}
                </h3>
                <div class="flex items-start gap-3">
                   <span class="text-[var(--color-accent)] font-black">A:</span>
                   <p class="text-[var(--color-text-mid)] font-medium leading-relaxed">
                    {{ item.a }}
                  </p>
                </div>
              </div>
            </div>
          </div>

          <div class="pt-8 border-t border-[var(--color-sand-dark)] text-center">
            <p class="text-[var(--color-text-muted)] font-medium mb-6">หากยังมีข้อสงสัยด้านอื่นๆ สามารถสอบถามเราได้โดยตรง</p>
             <router-link to="/contact" class="inline-flex items-center gap-2 px-8 py-3.5 border-2 border-[var(--color-primary)] text-[var(--color-primary)] rounded-full font-bold hover:bg-[var(--color-primary)] hover:text-white transition-all">
              <span class="material-symbols-rounded text-[20px]">chat</span>
              ติดต่อเจ้าหน้าที่
            </router-link>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'

const faqGroups = [
  {
    title: 'การจองและชำระเงิน',
    color: 'bg-[var(--color-primary)]',
    questions: [
      { q: 'ต้องชำระเงินมัดจำเท่าไหร่?', a: 'โดยปกติเราให้ชำระเงินเต็มจำนวนเพื่อเป็นการยืนยันที่นั่งและดำเนินการจองที่พัก/ตั๋วเครื่องบินในทันที ยกเว้นทริปพิเศษเป็นกรณีไป' },
      { q: 'สามารถออกใบกำกับภาษีได้หรือไม่?', a: 'ได้ครับ หากท่านจองในนามนิติบุคคล โปรดแจ้งข้อมูลทาง LINE @luilaykhao หลังจากจองสำเร็จ' },
    ]
  },
  {
    title: 'การเตรียมตัวเดินทาง',
    color: 'bg-[var(--color-accent)]',
    questions: [
      { q: 'ต้องเตรียมอุปกรณ์อะไรไปบ้างในทริปเดินป่า?', a: 'แต่ละทริปจะมี "สิ่งที่ต้องเตรียม" ระบุไว้ในหน้ารายละเอียดทริปครับ เบื้องต้นควรมีรองเท้าเดินป่า เสื้อผ้าแห้งไว และขวดน้ำส่วนตัว' },
      { q: 'กรณีแพ้อาหารต้องแจ้งตอนไหน?', a: 'สามารถระบุได้ในขั้นตอนการจองในส่วนของ "หมายเหตุ" หรือแจ้งทีมงานทันทีหลังจากจองสำเร็จครับ' },
    ]
  },
  {
    title: 'นโยบายการเปลี่ยนแปลง',
    color: 'bg-[var(--color-gold)]',
    questions: [
      { q: 'หากติดธุระด่วนสามารถส่งต่อที่นั่งให้เพื่อนได้ไหม?', a: 'สามารถทำได้ครับ แต่ต้องแจ้งทีมงานล่วงหน้าอย่างน้อย 3-7 วันเพื่อเปลี่ยนชื่อผู้ทำประกันและประสานงานจุดนัดพบ' },
      { q: 'ถ้าฝนตกหนักทริปจะยกเลิกไหม?', a: 'เรายึดถือความปลอดภัยเป็นสำคัญ หากสภาพอากาศไม่เอื้ออำนวย ไกด์ผู้เชี่ยวชาญจะประเมินหน้างาน หรือหากจำเป็นต้องยกเลิกทริป เรายินดีคืนเงินเต็มจำนวนครับ' },
    ]
  }
];

onMounted(() => {
  window.scrollTo(0, 0)
})
</script>

<style scoped>
.faq-page {
  animation: fadeIn 0.8s ease-out;
}
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
</style>
