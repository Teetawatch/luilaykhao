<template>
  <div class="terms-page font-anuphan bg-[var(--color-sand)] min-h-screen">
    <!-- HERO -->
    <section class="relative min-h-[300px] flex items-center overflow-hidden -mt-16 bg-[var(--color-primary)]">
      <div class="absolute inset-0">
        <img
          src="/images/landscape.webp"
          alt="ทิวเขาในทริปของลุยเลเขา"
          class="w-full h-full object-cover"
        />
        <div class="absolute inset-0 bg-black/40"></div>
      </div>
      <div class="relative z-10 w-full px-6 md:px-8 py-24 md:py-32 text-center flex flex-col items-center">
        <div class="w-16 h-1.5 bg-[var(--color-accent)] mb-6 rounded-full"></div>
        <h1 class="text-4xl md:text-6xl font-black text-white leading-tight mb-4 tracking-tight">
          เงื่อนไขการให้บริการ
        </h1>
        <p class="text-base md:text-lg text-white/85 max-w-2xl mx-auto leading-relaxed">
          เงื่อนไขฉบับนี้คือข้อตกลงระหว่างท่านกับลุยเลเขา และเป็นชุดเดียวกับที่แสดงในขั้นตอนยืนยันการจอง
        </p>
        <p v-if="effectiveLabel" class="mt-5 text-sm text-white/75">
          ฉบับที่ {{ version }} · มีผลตั้งแต่ {{ effectiveLabel }}
        </p>
      </div>
    </section>

    <!-- CONTENT -->
    <section class="py-16 md:py-24">
      <div class="max-w-4xl mx-auto px-6 md:px-8">
        <div class="bg-white rounded-[2.5rem] p-8 md:p-12 border border-[var(--color-sand-dark)] space-y-12">

          <!-- ผู้ให้บริการ — ตัวตนตามใบอนุญาต มาก่อนเนื้อหาอื่นทั้งหมด -->
          <div class="rounded-2xl border border-[var(--color-sand-dark)] bg-[var(--color-sand)] p-6 space-y-3">
            <h2 class="text-lg font-extrabold text-[var(--color-text-dark)]">ผู้ให้บริการ</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-[10rem_1fr] gap-x-6 gap-y-2 text-sm text-[var(--color-text-mid)]">
              <dt class="font-bold text-[var(--color-text-dark)]">ชื่อที่ใช้ในการค้า</dt>
              <dd>ลุยเลเขา (LuiLayKhao)</dd>

              <template v-if="operator">
                <dt class="font-bold text-[var(--color-text-dark)]">ผู้ประกอบการ</dt>
                <dd>{{ operator }}</dd>
              </template>

              <dt class="font-bold text-[var(--color-text-dark)]">ใบอนุญาตนำเที่ยว</dt>
              <dd>
                เลขที่ {{ licence }}
                <a v-if="licenceImage" :href="licenceImage" target="_blank" rel="noopener"
                  class="text-[var(--color-accent)] font-semibold hover:underline ml-1">ดูใบอนุญาต</a>
              </dd>

              <template v-if="address">
                <dt class="font-bold text-[var(--color-text-dark)]">ที่อยู่</dt>
                <dd>{{ address }}</dd>
              </template>

              <dt class="font-bold text-[var(--color-text-dark)]">ติดต่อ</dt>
              <dd>
                <a :href="supportPhoneHref()" class="hover:text-[var(--color-accent)]">{{ supportPhone() }}</a>
                · <a :href="supportEmailHref()" class="hover:text-[var(--color-accent)]">{{ supportEmail() }}</a>
                · <a :href="supportLineUrl()" target="_blank" rel="noopener" class="hover:text-[var(--color-accent)]">LINE {{ supportLine() }}</a>
                <span class="block text-[var(--color-text-muted)] mt-0.5">{{ supportHours() }}</span>
              </dd>
            </dl>
          </div>

          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-[var(--color-gold)] rounded-full"></span>
              ความเข้าใจเบื้องต้น
            </h2>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              เมื่อท่านใช้งานเว็บไซต์ แอปพลิเคชัน หรือจองทริปกับลุยเลเขา ถือว่าท่านได้อ่านและตกลงผูกพันตามเงื่อนไขฉบับนี้
              หากท่านจองแทนผู้อื่น ท่านรับรองว่าได้แจ้งเงื่อนไขนี้ให้ผู้เดินทางทุกท่านทราบแล้ว
            </p>
          </div>

          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-[var(--color-accent)] rounded-full"></span>
              1. การจองและการยืนยันสิทธิ์
            </h2>
            <ul class="space-y-3">
              <li v-for="item in bookingRules" :key="item" :class="bulletClass">
                <span class="material-symbols-rounded text-[var(--color-accent)] shrink-0 text-[20px]">check_circle</span>
                <span v-html="item"></span>
              </li>
            </ul>
          </div>

          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-[var(--color-primary)] rounded-full"></span>
              2. ราคาและการชำระเงิน
            </h2>
            <ul class="space-y-3">
              <li v-for="item in paymentRules" :key="item" :class="bulletClass">
                <span class="material-symbols-rounded text-[var(--color-accent)] shrink-0 text-[20px]">payments</span>
                <span v-html="item"></span>
              </li>
            </ul>
          </div>

          <!-- หัวใจของเอกสาร — ข้อความชุดเดียวกับที่กล่องยืนยันก่อนจองแสดง -->
          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-red-400 rounded-full"></span>
              3. การยกเลิก การเลื่อน และการเปลี่ยนตัวผู้เดินทาง
            </h2>
            <div class="rounded-2xl border-2 border-red-200 bg-red-50/60 p-6 space-y-4">
              <p class="text-sm font-bold text-red-900 flex items-center gap-2">
                <span class="material-symbols-rounded text-[20px]">gavel</span>
                ข้อความชุดนี้คือชุดเดียวกับที่ท่านกดยอมรับก่อนยืนยันการจอง
              </p>
              <ol class="space-y-3 text-sm text-red-900 leading-relaxed">
                <li v-for="(rule, i) in bookingTerms" :key="i" class="flex gap-3">
                  <span class="font-black shrink-0">{{ i + 1 }}.</span>
                  <span v-html="rule"></span>
                </li>
              </ol>
            </div>
            <p class="text-sm text-[var(--color-text-muted)]">
              เหตุผลที่ไม่คืนเงิน: ค่ามัดจำถูกนำไปสำรองจ่ายค่าอุทยาน ที่พัก และยานพาหนะล่วงหน้าตั้งแต่รอบยังไม่เต็ม
              ซึ่งเป็นค่าใช้จ่ายที่เรียกคืนไม่ได้เมื่อมีผู้สละสิทธิ์
            </p>
          </div>

          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-[var(--color-accent)] rounded-full"></span>
              4. กรณีที่เราเป็นฝ่ายยกเลิกหรือเปลี่ยนแปลงรอบเดินทาง
            </h2>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              รอบเดินทางต้องมีผู้ร่วมเดินทางถึงจำนวนขั้นต่ำที่ระบุไว้ในแต่ละรอบจึงจะออกเดินทาง
              หากไม่ถึงจำนวน หรือมีเหตุที่ทำให้เดินทางไม่ปลอดภัย เช่น สภาพอากาศรุนแรง อุทยานปิด หรือคำสั่งของหน่วยงานรัฐ
              ทีมงานจะแจ้งท่านล่วงหน้าเร็วที่สุดเท่าที่ทราบ และท่านเลือกได้ระหว่าง
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
              <div class="p-5 rounded-2xl border border-blue-100 bg-blue-50/60 space-y-1">
                <h3 class="font-bold text-blue-900">รับเงินคืนเต็มจำนวน</h3>
                <p class="text-blue-800">คืน {{ POLICY.operatorCancelRefundPercent }}% ของยอดที่ชำระมาแล้ว โดยไม่หักค่าใช้จ่ายใด ๆ</p>
              </div>
              <div class="p-5 rounded-2xl border border-emerald-100 bg-emerald-50/60 space-y-1">
                <h3 class="font-bold text-emerald-900">เลื่อนไปรอบอื่น</h3>
                <p class="text-emerald-800">ย้ายยอดที่ชำระแล้วไปรอบใหม่ที่ท่านเลือก โดยไม่คิดค่าธรรมเนียมการเลื่อน</p>
              </div>
            </div>
            <p class="text-sm text-[var(--color-text-muted)]">
              การเปลี่ยนแปลงรายละเอียดปลีกย่อยของทริปที่ไม่กระทบสาระสำคัญ เช่น สลับลำดับจุดแวะ เปลี่ยนที่พักเป็นระดับเทียบเท่า
              หรือเปลี่ยนคันรถและทีมงาน ทีมงานขอสงวนสิทธิ์ดำเนินการได้โดยแจ้งให้ทราบ และไม่ถือเป็นเหตุขอคืนเงิน
            </p>
          </div>

          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-[var(--color-primary)] rounded-full"></span>
              5. หน้าที่ของผู้เดินทาง
            </h2>
            <ul class="list-disc pl-6 space-y-2.5 text-[var(--color-text-mid)] leading-relaxed">
              <li>ให้ข้อมูลผู้เดินทางตามความจริง ทั้งชื่อ-นามสกุล เลขบัตรประชาชนหรือหนังสือเดินทาง เบอร์ติดต่อฉุกเฉิน โรคประจำตัวและอาหารที่แพ้ เพราะข้อมูลชุดนี้ใช้ทำประกันภัยการเดินทางและใช้ช่วยเหลือท่านหน้างาน</li>
              <li>ทริปต่างประเทศ ผู้เดินทางต้องมีหนังสือเดินทางที่มีอายุเหลือไม่น้อยกว่า 6 เดือนนับจากวันเดินทาง และรับผิดชอบเรื่องวีซ่าด้วยตนเอง เว้นแต่ระบุไว้เป็นอย่างอื่นในหน้าทริป</li>
              <li>ตรงต่อเวลาตามจุดนัดหมาย หากมาสายเกินเวลาที่นัดไว้จนคณะต้องออกเดินทางก่อน ถือว่าสละสิทธิ์และไม่สามารถขอคืนเงินได้</li>
              <li>ปฏิบัติตามคำแนะนำด้านความปลอดภัยของทีมงานและไกด์ตลอดการเดินทาง</li>
              <li>ประเมินความพร้อมของร่างกายตามระดับความยากที่ระบุไว้ในหน้าทริป และแจ้งทีมงานทันทีเมื่อรู้สึกผิดปกติ</li>
              <li>ดูแลธรรมชาติและผู้ร่วมทริปคนอื่น งดพฤติกรรมที่รบกวนผู้อื่นหรือสร้างความเสียหายต่อสถานที่ และนำขยะของตนกลับ</li>
            </ul>
          </div>

          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-[var(--color-gold)] rounded-full"></span>
              6. ขอบเขตความรับผิด
            </h2>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              ลุยเลเขาเป็นผู้ประกอบธุรกิจนำเที่ยวที่ได้รับใบอนุญาตเลขที่ {{ licence }} และรับผิดชอบต่อการจัดบริการตามที่ระบุไว้ในหน้าทริป
              พร้อมจัดให้มีประกันภัยการเดินทางตามที่กฎหมายว่าด้วยธุรกิจนำเที่ยวและมัคคุเทศก์กำหนด
            </p>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              ทั้งนี้ เราไม่อาจรับผิดต่อความเสียหายที่เกิดจากเหตุสุดวิสัยหรือเหตุที่อยู่นอกเหนือการควบคุมตามสมควร เช่น ภัยธรรมชาติ
              สภาพอากาศรุนแรง โรคระบาด คำสั่งของหน่วยงานรัฐ การจราจร หรือความล่าช้าของผู้ให้บริการขนส่งภายนอก
              รวมถึงความเสียหายที่เกิดจากการที่ผู้เดินทางให้ข้อมูลเท็จ ไม่ปฏิบัติตามคำแนะนำด้านความปลอดภัย หรือแยกตัวออกจากคณะโดยไม่แจ้งทีมงาน
              ข้อจำกัดนี้ไม่ตัดสิทธิ์ที่ท่านมีตามกฎหมายคุ้มครองผู้บริโภค
            </p>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              ทรัพย์สินส่วนตัวอยู่ในความรับผิดชอบของผู้เดินทางเอง โปรดเก็บของมีค่าไว้กับตัวตลอดการเดินทาง
            </p>
          </div>

          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-[var(--color-accent)] rounded-full"></span>
              7. เนื้อหาที่ผู้ใช้โพสต์
            </h2>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              รูปภาพ รีวิว และข้อความที่ท่านโพสต์ยังเป็นของท่าน แต่ท่านอนุญาตให้เราเผยแพร่บนช่องทางของลุยเลเขาได้โดยระบุชื่อผู้ถ่าย
              เราขอสงวนสิทธิ์ลบหรือซ่อนเนื้อหาที่ผิดกฎหมาย ละเมิดสิทธิ์ผู้อื่น หรือถูกรายงานว่าไม่เหมาะสม
              และท่านสามารถรายงานหรือบล็อกเนื้อหาที่ไม่เหมาะสมได้จากในแอปและบนเว็บไซต์
            </p>
          </div>

          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-[var(--color-primary)] rounded-full"></span>
              8. ข้อมูลส่วนบุคคล
            </h2>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              การเก็บ ใช้ และเปิดเผยข้อมูลส่วนบุคคลเป็นไปตาม<router-link to="/privacy" class="text-[var(--color-accent)] font-semibold hover:underline">นโยบายความเป็นส่วนตัว</router-link>
              ซึ่งถือเป็นส่วนหนึ่งของเงื่อนไขฉบับนี้
            </p>
          </div>

          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-[var(--color-gold)] rounded-full"></span>
              9. การร้องเรียนและกฎหมายที่ใช้บังคับ
            </h2>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              หากท่านไม่ได้รับบริการตามที่ตกลงไว้ โปรดแจ้งทีมงานผ่านช่องทางติดต่อด้านบน หรือ<router-link to="/contact" class="text-[var(--color-accent)] font-semibold hover:underline">แบบฟอร์มติดต่อเรา</router-link>
              เราจะติดต่อกลับภายใน 3 วันทำการ หากยังไม่ได้ข้อยุติ ท่านมีสิทธิ์ร้องเรียนต่อกรมการท่องเที่ยว
              กระทรวงการท่องเที่ยวและกีฬา หรือสำนักงานคณะกรรมการคุ้มครองผู้บริโภค (สคบ.) สายด่วน 1166
            </p>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              เงื่อนไขฉบับนี้อยู่ภายใต้บังคับกฎหมายไทย เราอาจปรับปรุงเงื่อนไขได้เป็นครั้งคราวโดยประกาศฉบับใหม่บนหน้านี้
              การจองแต่ละครั้งผูกพันตามเงื่อนไขฉบับที่ประกาศอยู่ ณ วันที่ท่านกดยอมรับ ไม่ใช่ฉบับที่แก้ไขภายหลัง
            </p>
          </div>

          <div class="pt-8 border-t border-[var(--color-sand-dark)] text-sm text-[var(--color-text-muted)] space-y-1">
            <p v-if="effectiveLabel">เงื่อนไขฉบับที่ {{ version }} · ปรับปรุงล่าสุดเมื่อ {{ effectiveLabel }}</p>
            <p>
              สอบถามเพิ่มเติม LINE
              <a :href="supportLineUrl()" target="_blank" rel="noopener" class="text-[var(--color-accent)] font-semibold hover:underline">{{ supportLine() }}</a>
              หรือโทร
              <a :href="supportPhoneHref()" class="text-[var(--color-accent)] font-semibold hover:underline">{{ supportPhone() }}</a>
              ({{ supportHours() }})
            </p>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { BOOKING_TERMS, POLICY, termsEffectiveLabel, termsVersion } from '../lib/policy'
import { licenceImageUrl, licenceNo } from '../lib/licence'
import {
  operatorAddress, operatorName,
  supportEmail, supportEmailHref, supportHours,
  supportLine, supportLineUrl, supportPhone, supportPhoneHref,
} from '../lib/contact'

const headingClass = 'text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3'
const bulletClass = 'flex gap-3 text-[var(--color-text-mid)] leading-relaxed'

const bookingTerms = BOOKING_TERMS
const licence = licenceNo()
const licenceImage = licenceImageUrl()
const version = termsVersion()
const effectiveLabel = termsEffectiveLabel()

// ว่างได้ — แอดมินยังไม่ได้กรอกที่ /admin/settings ก็ซ่อนบรรทัดไปเลย
const operator = computed(() => operatorName())
const address = computed(() => operatorAddress())

const bookingRules = [
  `การจองจะสมบูรณ์เมื่อชำระเงินครบตามยอดและภายในเวลาที่กำหนดเท่านั้น ระบบจะกันที่นั่งไว้ให้ระหว่างทำรายการ และคืนที่นั่งเข้าระบบอัตโนมัติเมื่อไม่ชำระเงินภายใน <strong>${POLICY.paymentWindowMinutes} นาที</strong>`,
  'ที่นั่งบนรถจัดสรรตามที่ท่านเลือกไว้ในผังที่นั่ง หรือตามลำดับการจองในรอบที่ไม่เปิดให้เลือกที่นั่ง',
  'ทริปที่เดินทางด้วยเครื่องบิน เลขที่นั่งเป็นไปตามที่สายการบินกำหนด ไม่ได้เลือกผ่านระบบของเรา',
  `ท่านขอเปลี่ยนวันเดินทางเองผ่านระบบได้ก่อนออกเดินทางอย่างน้อย <strong>${POLICY.rescheduleLeadDays} วัน</strong> และอยู่ภายใต้เงื่อนไขข้อ 3`,
  'ใบจองมีผลเฉพาะกับผู้เดินทางตามรายชื่อที่ระบุไว้ การโอนสิทธิ์ให้ผู้อื่นเป็นไปตามข้อ 3',
]

const paymentRules = [
  'ราคาที่แสดงเป็นราคาต่อท่าน และครอบคลุมเฉพาะรายการที่ระบุไว้ในหัวข้อ "ราคานี้รวมอะไรบ้าง" ของแต่ละทริป',
  'จุดขึ้นรถบางจุด ประเภทรถที่เลือก อุปกรณ์เช่า และบริการเสริม มีค่าใช้จ่ายต่างกัน ระบบจะแสดงยอดรวมสุทธิให้เห็นก่อนกดชำระเงินเสมอ',
  `ชำระได้ทั้งแบบเต็มจำนวน มัดจำ ผ่อนชำระ และแบ่งจ่ายกันในกลุ่ม ตามตัวเลือกที่เปิดไว้ในแต่ละรอบ กรณีมัดจำหรือผ่อนชำระ ต้องชำระให้ครบก่อนเดินทางอย่างน้อย <strong>${POLICY.balanceDueDays} วัน</strong> หรือตามวันครบกำหนดที่ระบุในใบจอง`,
  'หากไม่ชำระตามกำหนด ทีมงานขอสงวนสิทธิ์ยกเลิกใบจองและถือว่าสละสิทธิ์การเดินทาง โดยเป็นไปตามเงื่อนไขการยกเลิกในข้อ 3',
  'ใบเสร็จรับเงินอิเล็กทรอนิกส์ออกให้ทุกครั้งที่ชำระเงินสำเร็จ และดาวน์โหลดได้จากหน้าใบจองของท่าน',
]

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

@media (prefers-reduced-motion: reduce) {
  .terms-page { animation: none; }
}
</style>
