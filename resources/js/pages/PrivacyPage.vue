<template>
  <div class="privacy-page font-anuphan bg-[var(--color-sand)] min-h-screen">
    <!-- HERO SECTION -->
    <section class="relative min-h-[300px] flex items-center overflow-hidden -mt-16 bg-[#0D2B1E]">
      <div class="absolute inset-0">
        <img
          src="/images/phusoidao.png"
          alt="Privacy Policy"
          class="w-full h-full object-cover opacity-60"
        />
        <div class="absolute inset-0 bg-gradient-to-b from-[#0D2B1E]/60 via-[#1A3A2E]/40 to-[#0D2B1E]/90"></div>
      </div>
      <div class="relative z-10 w-full px-6 md:px-8 py-24 md:py-32 text-center">
        <h1 class="text-4xl md:text-6xl font-extrabold text-white leading-tight mb-4 drop-shadow-lg">
          นโยบายความเป็นส่วนตัว
        </h1>
        <p class="text-lg md:text-xl text-white/90 font-medium max-w-2xl mx-auto">
          ความสัมพันธ์ที่ยั่งยืนเริ่มต้นจากความไว้วางใจ เราปกป้องข้อมูลของคุณเหมือนเพื่อนดูแลเพื่อน
        </p>
      </div>
    </section>

    <!-- CONTENT SECTION -->
    <section class="py-16 md:py-24">
      <div class="max-w-4xl mx-auto px-6 md:px-8">
        <div class="bg-white rounded-[2.5rem] p-8 md:p-12 shadow-sm border border-[var(--color-sand-dark)] space-y-12">
          
          <div class="space-y-6">
            <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-8 bg-[var(--color-accent)] rounded-full"></span>
              ความนำ
            </h2>
            <p class="text-lg text-[var(--color-text-mid)] leading-relaxed">
              ลุยเลเขา (LuiLayKhao) ให้ความสำคัญกับการคุ้มครองข้อมูลส่วนบุคคลของท่าน นโยบายนี้อธิบายถึงวิธีที่เราเก็บรวบรวม ใช้ และเปิดเผยข้อมูลส่วนบุคคลของท่านเมื่อท่านใช้งานแพลตฟอร์มของเรา
            </p>
          </div>

          <div class="space-y-6">
            <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-8 bg-[var(--color-gold)] rounded-full"></span>
              1. ข้อมูลที่เราเก็บรวบรวม
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div class="bg-[var(--color-sand)] p-6 rounded-2xl border border-[var(--color-sand-dark)]/50">
                <h3 class="font-bold text-[var(--color-text-dark)] mb-3 flex items-center gap-2">
                  <span class="material-symbols-rounded text-[var(--color-accent)]">person</span>
                  ข้อมูลยืนยันตัวตน
                </h3>
                <p class="text-[var(--color-text-mid)]">ชื่อ-นามสกุล, ที่อยู่อีเมล, หมายเลขโทรศัพท์ และรายละเอียดการติดต่ออื่นๆ</p>
              </div>
              <div class="bg-[var(--color-sand)] p-6 rounded-2xl border border-[var(--color-sand-dark)]/50">
                <h3 class="font-bold text-[var(--color-text-dark)] mb-3 flex items-center gap-2">
                  <span class="material-symbols-rounded text-[var(--color-accent)]">payments</span>
                  ข้อมูลการทำรายการ
                </h3>
                <p class="text-[var(--color-text-mid)]">ประวัติการจองทริป, รายละเอียดการชำระเงิน (เราไม่เก็บข้อมูลบัตรเครดิตโดยตรง)</p>
              </div>
              <div class="bg-[var(--color-sand)] p-6 rounded-2xl border border-[var(--color-sand-dark)]/50">
                <h3 class="font-bold text-[var(--color-text-dark)] mb-3 flex items-center gap-2">
                  <span class="material-symbols-rounded text-[var(--color-accent)]">devices</span>
                  ข้อมูลทางเทคนิค
                </h3>
                <p class="text-[var(--color-text-mid)]">ที่อยู่ IP, ประเภทเบราว์เซอร์, คุกกี้ และพฤติกรรมการใช้งานในระบบ</p>
              </div>
              <div class="bg-[var(--color-sand)] p-6 rounded-2xl border border-[var(--color-sand-dark)]/50">
                <h3 class="font-bold text-[var(--color-text-dark)] mb-3 flex items-center gap-2">
                  <span class="material-symbols-rounded text-[var(--color-accent)]">directions_run</span>
                  ข้อมูลทริป
                </h3>
                <p class="text-[var(--color-text-mid)]">ความต้องการพิเศษ (เช่น การแพ้อาหาร) เพื่อให้แน่ใจว่าท่านจะได้รับบริการที่ปลอดภัย</p>
              </div>
            </div>
          </div>

          <div class="space-y-6">
            <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-8 bg-[var(--color-primary)] rounded-full"></span>
              2. เราใช้ข้อมูลของคุณอย่างไร
            </h2>
            <ul class="space-y-4">
              <li class="flex gap-4">
                <span class="material-symbols-rounded text-[var(--color-accent)] shrink-0">check_circle</span>
                <span class="text-[var(--color-text-mid)]">เพื่อดำเนินการจองทริป ออกตั๋ว และยืนยันการทำรายการ</span>
              </li>
              <li class="flex gap-4">
                <span class="material-symbols-rounded text-[var(--color-accent)] shrink-0">check_circle</span>
                <span class="text-[var(--color-text-mid)]">เพื่อสื่อสารข้อมูลสำคัญเกี่ยวกับทริปหรือการเปลี่ยนแปลงต่างๆ</span>
              </li>
              <li class="flex gap-4">
                <span class="material-symbols-rounded text-[var(--color-accent)] shrink-0">check_circle</span>
                <span class="text-[var(--color-text-mid)]">เพื่อพัฒนาและปรับปรุงคุณภาพการบริการของเราให้ดีขึ้น</span>
              </li>
              <li class="flex gap-4">
                <span class="material-symbols-rounded text-[var(--color-accent)] shrink-0">check_circle</span>
                <span class="text-[var(--color-text-mid)]">เพื่อการวิเคราะห์ทางสถิติและการตลาด (โดยไม่ระบุตัวตนรายบุคคล)</span>
              </li>
            </ul>
          </div>

          <div class="space-y-6">
            <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-8 bg-[var(--color-accent)] rounded-full"></span>
              3. การเปิดเผยข้อมูลแก่บุคคลภายนอก
            </h2>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              เราจะเปิดเผยข้อมูลส่วนบุคคลของท่านให้กับบุคคลภายนอกเฉพาะในกรณีที่จำเป็นเท่านั้น เช่น:
            </p>
            <div class="p-6 bg-white border-l-4 border-[var(--color-gold)] shadow-sm italic text-[var(--color-text-mid)]">
              "เราจะส่งเฉพาะข้อมูลที่จำเป็นให้กับผู้ให้บริการทริป เพื่อให้เขารองรับการเดินทางของคุณได้อย่างราบรื่นที่สุด"
            </div>
            <ul class="list-disc pl-6 space-y-2 text-[var(--color-text-mid)]">
              <li>ผู้ให้บริการทริปหรือทัวร์ที่ท่านได้ทำการจอง</li>
              <li>ผู้ให้บริการระบบชำระเงินที่ปลอดภัย</li>
              <li>หน่วยงานภาครัฐตามที่กฎหมายกำหนด</li>
            </ul>
          </div>

          <div class="space-y-6">
            <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-8 bg-[var(--color-primary)] rounded-full"></span>
              4. สิทธิของท่านในฐานะเจ้าของข้อมูล
            </h2>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              ท่านมีสิทธิ์ภายใต้ พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล (PDPA) ดังนี้:
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="p-4 bg-[var(--color-sand)] rounded-xl text-center font-bold text-[var(--color-text-dark)]">สิทธิในการเข้าถึงข้อมูล</div>
              <div class="p-4 bg-[var(--color-sand)] rounded-xl text-center font-bold text-[var(--color-text-dark)]">สิทธิในการขอแก้ไขข้อมูล</div>
              <div class="p-4 bg-[var(--color-sand)] rounded-xl text-center font-bold text-[var(--color-text-dark)]">สิทธิในการขอลบข้อมูล</div>
              <div class="p-4 bg-[var(--color-sand)] rounded-xl text-center font-bold text-[var(--color-text-dark)]">สิทธิในการถอนความยินยอม</div>
            </div>
          </div>

          <div class="pt-8 border-t border-[var(--color-sand-dark)]">
            <p class="text-[var(--color-text-muted)] text-sm text-center">
              ปรับปรุงล่าสุดเมื่อ: 4 เมษายน 2569<br>
              หากมีคำถามเพิ่มเติม โปรดติดต่อเราผ่านหน้าติดต่อเราหรืออีเมล support@luilaykhao.com
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
.privacy-page {
  animation: fadeIn 0.8s ease-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
</style>
