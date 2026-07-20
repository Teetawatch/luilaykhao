<template>
  <div class="privacy-page font-anuphan bg-[var(--color-sand)] min-h-screen">
    <!-- HERO SECTION -->
    <section class="relative min-h-[300px] flex items-center overflow-hidden -mt-16 bg-[var(--color-primary)]">
      <div class="absolute inset-0">
        <img
          src="/images/phusoidao.webp"
          alt="Privacy Policy"
          class="w-full h-full object-cover"
        />
        <div class="absolute inset-0 bg-black/20"></div>
      </div>
      <div class="relative z-10 w-full px-6 md:px-8 py-24 md:py-32 text-center flex flex-col items-center">
        <div class="w-16 h-1.5 bg-[var(--color-accent)] mb-6 rounded-full"></div>
        <h1 class="text-4xl md:text-6xl font-black text-white leading-tight mb-4 tracking-tight">
          นโยบายความเป็นส่วนตัว
        </h1>
        <p class="text-lg md:text-xl text-white/80 font-bold max-w-2xl mx-auto tracking-wide">
          ความสัมพันธ์ที่ยั่งยืนเริ่มต้นจากความไว้วางใจ เราปกป้องข้อมูลของคุณเหมือนเพื่อนดูแลเพื่อน
        </p>
      </div>
    </section>

    <!-- CONTENT SECTION -->
    <section class="py-16 md:py-24">
      <div class="max-w-4xl mx-auto px-6 md:px-8">
        <div class="bg-white rounded-[2.5rem] p-8 md:p-12 border border-[var(--color-sand-dark)] space-y-12">
          
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
                <p class="text-[var(--color-text-mid)]">ประวัติการจองทริป, รายละเอียดการชำระเงิน (เราไม่เก็บข้อมูลบัตรเครดิตโดยตรง ข้อมูลบัตรจัดการโดยผู้ให้บริการระบบชำระเงิน)</p>
              </div>
              <div class="bg-[var(--color-sand)] p-6 rounded-2xl border border-[var(--color-sand-dark)]/50">
                <h3 class="font-bold text-[var(--color-text-dark)] mb-3 flex items-center gap-2">
                  <span class="material-symbols-rounded text-[var(--color-accent)]">my_location</span>
                  ข้อมูลตำแหน่ง (GPS)
                </h3>
                <p class="text-[var(--color-text-mid)]">ตำแหน่ง GPS ของท่านขณะใช้งานฟีเจอร์ติดตามรถรับส่งหรือค้นหาจุดรับ-ส่ง เราไม่บันทึกประวัติตำแหน่งของท่านไว้บนเซิร์ฟเวอร์</p>
              </div>
              <div class="bg-[var(--color-sand)] p-6 rounded-2xl border border-[var(--color-sand-dark)]/50">
                <h3 class="font-bold text-[var(--color-text-dark)] mb-3 flex items-center gap-2">
                  <span class="material-symbols-rounded text-[var(--color-accent)]">directions_run</span>
                  ข้อมูลทริป
                </h3>
                <p class="text-[var(--color-text-mid)]">ความต้องการพิเศษ (เช่น การแพ้อาหาร หรือสุขภาพ) เพื่อให้แน่ใจว่าท่านจะได้รับบริการที่ปลอดภัย</p>
              </div>
              <div class="bg-[var(--color-sand)] p-6 rounded-2xl border border-[var(--color-sand-dark)]/50">
                <h3 class="font-bold text-[var(--color-text-dark)] mb-3 flex items-center gap-2">
                  <span class="material-symbols-rounded text-[var(--color-accent)]">devices</span>
                  ข้อมูลทางเทคนิค
                </h3>
                <p class="text-[var(--color-text-mid)]">ที่อยู่ IP, ประเภทอุปกรณ์, คุกกี้ และพฤติกรรมการใช้งานในระบบ</p>
              </div>
              <div class="bg-[var(--color-sand)] p-6 rounded-2xl border border-[var(--color-sand-dark)]/50">
                <h3 class="font-bold text-[var(--color-text-dark)] mb-3 flex items-center gap-2">
                  <span class="material-symbols-rounded text-[var(--color-accent)]">bug_report</span>
                  ข้อมูล Crash & Analytics
                </h3>
                <p class="text-[var(--color-text-mid)]">ข้อมูลการขัดข้องของแอปและสถิติการใช้งาน เพื่อปรับปรุงความเสถียรและประสิทธิภาพ จัดการโดย Google Firebase</p>
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
            <div class="p-6 bg-white border-l-4 border-[var(--color-gold)] italic text-[var(--color-text-mid)]">
              "เราจะส่งเฉพาะข้อมูลที่จำเป็นให้กับผู้ให้บริการทริป เพื่อให้เขารองรับการเดินทางของคุณได้อย่างราบรื่นที่สุด"
            </div>
            <ul class="space-y-3 text-[var(--color-text-mid)]">
              <li class="flex gap-3 items-start">
                <span class="material-symbols-rounded text-[var(--color-accent)] shrink-0 mt-0.5">hiking</span>
                <span><strong class="text-[var(--color-text-dark)]">ผู้ให้บริการทริป</strong> — ชื่อ เบอร์ และข้อมูลที่จำเป็นสำหรับการจัดทริปที่ท่านจอง</span>
              </li>
              <li class="flex gap-3 items-start">
                <span class="material-symbols-rounded text-[var(--color-accent)] shrink-0 mt-0.5">brand_awareness</span>
                <span><strong class="text-[var(--color-text-dark)]">Google Firebase</strong> — ระบบแจ้งเตือน (FCM), วิเคราะห์การใช้งาน (Analytics) และรายงาน crash (Crashlytics)</span>
              </li>
              <li class="flex gap-3 items-start">
                <span class="material-symbols-rounded text-[var(--color-accent)] shrink-0 mt-0.5">map</span>
                <span><strong class="text-[var(--color-text-dark)]">Google Maps Platform</strong> — คำนวณเวลาเดินทางโดยประมาณจากจุดรับ-ส่ง</span>
              </li>
              <li class="flex gap-3 items-start">
                <span class="material-symbols-rounded text-[var(--color-accent)] shrink-0 mt-0.5">account_balance</span>
                <span><strong class="text-[var(--color-text-dark)]">หน่วยงานภาครัฐ</strong> — เฉพาะกรณีที่กฎหมายกำหนดให้เปิดเผย</span>
              </li>
            </ul>
          </div>

          <div class="space-y-6">
            <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-8 bg-[var(--color-gold)] rounded-full"></span>
              4. ระยะเวลาการเก็บรักษาข้อมูล
            </h2>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              เราเก็บรักษาข้อมูลของท่านตราบเท่าที่จำเป็นสำหรับการให้บริการ หรือตามที่กฎหมายกำหนด ข้อมูลการจองและประวัติการชำระเงินจะถูกเก็บไว้ตามข้อกำหนดทางบัญชีและภาษีของประเทศไทย ท่านสามารถขอให้ลบข้อมูลส่วนบุคคลของท่านได้ทุกเมื่อผ่านช่องทางติดต่อด้านล่าง
            </p>
          </div>

          <div class="space-y-6">
            <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-8 bg-[var(--color-primary)] rounded-full"></span>
              5. สิทธิของท่านในฐานะเจ้าของข้อมูล
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

          <div class="pt-8 border-t border-[var(--color-sand-dark)] space-y-4">
            <h2 class="text-xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-6 bg-[var(--color-accent)] rounded-full"></span>
              ติดต่อเราเรื่องข้อมูลส่วนบุคคล
            </h2>
            <div class="flex flex-col sm:flex-row gap-4">
              <a
                href="mailto:luilaykhao.info@gmail.com"
                class="flex items-center gap-3 px-5 py-3 bg-[var(--color-sand)] rounded-xl border border-[var(--color-sand-dark)]/50 text-[var(--color-text-mid)] hover:border-[var(--color-accent)] transition-colors"
              >
                <span class="material-symbols-rounded text-[var(--color-accent)]">mail</span>
                <span>luilaykhao.info@gmail.com</span>
              </a>
              <a
                href="https://line.me/R/ti/p/@luilaykhao"
                target="_blank"
                rel="noopener noreferrer"
                class="flex items-center gap-3 px-5 py-3 bg-[var(--color-sand)] rounded-xl border border-[var(--color-sand-dark)]/50 text-[var(--color-text-mid)] hover:border-[var(--color-accent)] transition-colors"
              >
                <span class="material-symbols-rounded text-[var(--color-accent)]">chat</span>
                <span>LINE OA: @luilaykhao</span>
              </a>
            </div>
            <p class="text-[var(--color-text-muted)] text-sm">
              ปรับปรุงล่าสุดเมื่อ: 23 พฤษภาคม 2569
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
