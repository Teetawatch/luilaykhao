<template>
  <div class="privacy-page font-anuphan bg-[var(--color-sand)] min-h-screen">
    <!-- HERO -->
    <section class="relative min-h-[300px] flex items-center overflow-hidden -mt-16 bg-[var(--color-primary)]">
      <div class="absolute inset-0">
        <img
          src="/images/phusoidao.webp"
          alt="ทะเลหมอกที่ภูสอยดาว"
          class="w-full h-full object-cover"
        />
        <div class="absolute inset-0 bg-black/40"></div>
      </div>
      <div class="relative z-10 w-full px-6 md:px-8 py-24 md:py-32 text-center flex flex-col items-center">
        <div class="w-16 h-1.5 bg-[var(--color-accent)] mb-6 rounded-full"></div>
        <h1 class="text-4xl md:text-6xl font-black text-white leading-tight mb-4 tracking-tight">
          นโยบายความเป็นส่วนตัว
        </h1>
        <p class="text-base md:text-lg text-white/85 max-w-2xl mx-auto leading-relaxed">
          นโยบายนี้อธิบายว่าเราเก็บข้อมูลอะไร เก็บไปทำอะไร ส่งต่อให้ใคร เก็บไว้นานเท่าไร และท่านสั่งให้เราทำอะไรกับข้อมูลได้บ้าง
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

          <!-- ผู้ควบคุมข้อมูลส่วนบุคคล — PDPA บังคับให้ระบุ ไม่ใช่ของประดับ -->
          <div class="rounded-2xl border border-[var(--color-sand-dark)] bg-[var(--color-sand)] p-6 space-y-3">
            <h2 class="text-lg font-extrabold text-[var(--color-text-dark)]">ผู้ควบคุมข้อมูลส่วนบุคคล</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-[10rem_1fr] gap-x-6 gap-y-2 text-sm text-[var(--color-text-mid)]">
              <dt class="font-bold text-[var(--color-text-dark)]">ชื่อที่ใช้ในการค้า</dt>
              <dd>ลุยเลเขา (LuiLayKhao)</dd>

              <template v-if="operator">
                <dt class="font-bold text-[var(--color-text-dark)]">ผู้ประกอบการ</dt>
                <dd>{{ operator }}</dd>
              </template>

              <dt class="font-bold text-[var(--color-text-dark)]">ใบอนุญาตนำเที่ยว</dt>
              <dd>เลขที่ {{ licence }}</dd>

              <template v-if="address">
                <dt class="font-bold text-[var(--color-text-dark)]">ที่อยู่</dt>
                <dd>{{ address }}</dd>
              </template>

              <dt class="font-bold text-[var(--color-text-dark)]">ติดต่อเรื่องข้อมูล</dt>
              <dd>
                <a :href="supportEmailHref()" class="hover:text-[var(--color-accent)]">{{ supportEmail() }}</a>
                · <a :href="supportPhoneHref()" class="hover:text-[var(--color-accent)]">{{ supportPhone() }}</a>
                <span class="block text-[var(--color-text-muted)] mt-0.5">{{ supportHours() }}</span>
              </dd>
            </dl>
          </div>

          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-[var(--color-gold)] rounded-full"></span>
              1. ข้อมูลที่เราเก็บ
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
              <div v-for="group in collected" :key="group.title"
                class="bg-[var(--color-sand)] p-6 rounded-2xl border border-[var(--color-sand-dark)]/50">
                <h3 class="font-bold text-[var(--color-text-dark)] mb-2 flex items-center gap-2">
                  <span class="material-symbols-rounded text-[var(--color-accent)]">{{ group.icon }}</span>
                  {{ group.title }}
                </h3>
                <p class="text-sm text-[var(--color-text-mid)] leading-relaxed">{{ group.body }}</p>
              </div>
            </div>
            <p class="text-sm text-[var(--color-text-muted)] leading-relaxed">
              เลขบัตรประชาชน ข้อมูลการแพ้อาหาร และบันทึกด้านสุขภาพ ถูกเข้ารหัสไว้ในฐานข้อมูล
              และแสดงให้เห็นเฉพาะทีมงานที่จำเป็นต้องใช้ในการดูแลท่านหน้างานเท่านั้น
            </p>
          </div>

          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-[var(--color-primary)] rounded-full"></span>
              2. เราใช้ข้อมูลทำอะไร และอาศัยฐานทางกฎหมายใด
            </h2>
            <div class="overflow-x-auto">
              <table class="w-full text-sm border-collapse min-w-[34rem]">
                <thead>
                  <tr class="text-left text-[var(--color-text-dark)] border-b border-[var(--color-sand-dark)]">
                    <th class="py-3 pr-4 font-bold">วัตถุประสงค์</th>
                    <th class="py-3 font-bold w-[15rem]">ฐานทางกฎหมาย</th>
                  </tr>
                </thead>
                <tbody class="text-[var(--color-text-mid)]">
                  <tr v-for="row in purposes" :key="row.purpose" class="border-b border-[var(--color-sand-dark)]/50 align-top">
                    <td class="py-3 pr-4 leading-relaxed">{{ row.purpose }}</td>
                    <td class="py-3 leading-relaxed">{{ row.basis }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-[var(--color-accent)] rounded-full"></span>
              3. เราส่งข้อมูลให้ใครบ้าง
            </h2>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              เราไม่ขายข้อมูลของท่าน และส่งต่อเฉพาะเท่าที่จำเป็นต่อการให้บริการ ผู้รับข้อมูลแต่ละรายได้รับเฉพาะส่วนที่ตนต้องใช้
            </p>
            <ul class="space-y-3">
              <li v-for="party in recipients" :key="party.name" class="flex gap-3 items-start text-[var(--color-text-mid)]">
                <span class="material-symbols-rounded text-[var(--color-accent)] shrink-0 mt-0.5 text-[20px]">{{ party.icon }}</span>
                <span>
                  <strong class="text-[var(--color-text-dark)]">{{ party.name }}</strong> — {{ party.body }}
                </span>
              </li>
            </ul>
            <div class="p-5 rounded-2xl bg-[var(--color-sand)] border border-[var(--color-sand-dark)] text-sm text-[var(--color-text-mid)] leading-relaxed">
              ผู้ให้บริการบางรายมีเซิร์ฟเวอร์อยู่ต่างประเทศ การใช้บริการเหล่านี้จึงมีการส่งข้อมูลออกนอกราชอาณาจักร
              เราเลือกผู้ให้บริการที่มีมาตรฐานการคุ้มครองข้อมูลเทียบเท่าที่กฎหมายไทยกำหนด และส่งเท่าที่จำเป็นต่อการทำงานนั้น ๆ
            </div>
          </div>

          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-[var(--color-gold)] rounded-full"></span>
              4. เก็บไว้นานแค่ไหน
            </h2>
            <ul class="space-y-3">
              <li v-for="row in retention" :key="row.what" class="flex gap-3 items-start text-[var(--color-text-mid)]">
                <span class="material-symbols-rounded text-[var(--color-accent)] shrink-0 mt-0.5 text-[20px]">schedule</span>
                <span><strong class="text-[var(--color-text-dark)]">{{ row.what }}</strong> — {{ row.how_long }}</span>
              </li>
            </ul>
          </div>

          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-[var(--color-primary)] rounded-full"></span>
              5. คุกกี้และการวัดผล
            </h2>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              เว็บไซต์ใช้คุกกี้ที่จำเป็นต่อการเข้าสู่ระบบและการจองเสมอ ส่วนคุกกี้เพื่อการวิเคราะห์และการตลาด
              จะทำงานก็ต่อเมื่อท่านกดยอมรับในแถบความยินยอมเท่านั้น และท่านเปลี่ยนใจได้ทุกเมื่อโดยล้างคุกกี้ของเว็บไซต์นี้ในเบราว์เซอร์
            </p>
          </div>

          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-[var(--color-accent)] rounded-full"></span>
              6. สิทธิของท่านตาม PDPA
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div v-for="right in rights" :key="right.title"
                class="p-4 bg-[var(--color-sand)] rounded-xl border border-[var(--color-sand-dark)]/50">
                <p class="font-bold text-[var(--color-text-dark)] text-sm">{{ right.title }}</p>
                <p class="text-sm text-[var(--color-text-mid)] mt-1 leading-relaxed">{{ right.body }}</p>
              </div>
            </div>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              ใช้สิทธิ์ได้โดยติดต่อเราตามช่องทางด้านบน เราจะดำเนินการภายใน 30 วันนับจากวันที่ได้รับคำขอ
              บางกรณีเราอาจต้องเก็บข้อมูลบางส่วนต่อไปตามที่กฎหมายบัญชีและภาษีกำหนด และจะแจ้งเหตุผลให้ท่านทราบ
              หากท่านเห็นว่าเราไม่ปฏิบัติตามกฎหมาย ท่านมีสิทธิ์ร้องเรียนต่อสำนักงานคณะกรรมการคุ้มครองข้อมูลส่วนบุคคล (สคส.)
            </p>
          </div>

          <div class="space-y-4">
            <h2 :class="headingClass">
              <span class="w-2 h-8 bg-[var(--color-gold)] rounded-full"></span>
              7. ความปลอดภัยและข้อมูลของผู้เยาว์
            </h2>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              เราเข้ารหัสการเชื่อมต่อทั้งหมด จำกัดสิทธิ์การเข้าถึงข้อมูลไว้เฉพาะทีมงานที่จำเป็นต้องใช้
              และเข้ารหัสข้อมูลอ่อนไหวไว้ในฐานข้อมูลอีกชั้น หากเกิดเหตุละเมิดข้อมูลที่มีความเสี่ยงสูงต่อท่าน
              เราจะแจ้งท่านและหน่วยงานกำกับดูแลตามกรอบเวลาที่กฎหมายกำหนด
            </p>
            <p class="text-[var(--color-text-mid)] leading-relaxed">
              บัญชีผู้ใช้มีไว้สำหรับผู้ที่มีอายุ 18 ปีขึ้นไป ผู้เดินทางที่เป็นผู้เยาว์ต้องจองผ่านผู้ปกครอง
              ซึ่งเป็นผู้ให้ข้อมูลและความยินยอมแทน
            </p>
          </div>

          <div class="pt-8 border-t border-[var(--color-sand-dark)] space-y-4">
            <h2 class="text-xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-6 bg-[var(--color-accent)] rounded-full"></span>
              ติดต่อเราเรื่องข้อมูลส่วนบุคคล
            </h2>
            <div class="flex flex-col sm:flex-row gap-4">
              <a :href="supportEmailHref()"
                class="flex items-center gap-3 px-5 py-3 bg-[var(--color-sand)] rounded-xl border border-[var(--color-sand-dark)]/50 text-[var(--color-text-mid)] hover:border-[var(--color-accent)] transition-colors">
                <span class="material-symbols-rounded text-[var(--color-accent)]">mail</span>
                <span>{{ supportEmail() }}</span>
              </a>
              <a :href="supportLineUrl()" target="_blank" rel="noopener noreferrer"
                class="flex items-center gap-3 px-5 py-3 bg-[var(--color-sand)] rounded-xl border border-[var(--color-sand-dark)]/50 text-[var(--color-text-mid)] hover:border-[var(--color-accent)] transition-colors">
                <span class="material-symbols-rounded text-[var(--color-accent)]">chat</span>
                <span>LINE {{ supportLine() }}</span>
              </a>
            </div>
            <p v-if="effectiveLabel" class="text-[var(--color-text-muted)] text-sm">
              นโยบายฉบับที่ {{ version }} · ปรับปรุงล่าสุดเมื่อ {{ effectiveLabel }}
            </p>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { privacyEffectiveLabel, privacyVersion } from '../lib/policy'
import { licenceNo } from '../lib/licence'
import {
  operatorAddress, operatorName,
  supportEmail, supportEmailHref, supportHours,
  supportLine, supportLineUrl, supportPhone, supportPhoneHref,
} from '../lib/contact'

const headingClass = 'text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3'

const licence = licenceNo()
const version = privacyVersion()
const effectiveLabel = privacyEffectiveLabel()
const operator = computed(() => operatorName())
const address = computed(() => operatorAddress())

/*
   รายการนี้ต้องตรงกับสิ่งที่ระบบทำจริง ฉบับก่อนหน้าเขียนว่า "เราไม่บันทึก
   ประวัติตำแหน่งของท่านไว้บนเซิร์ฟเวอร์" ทั้งที่มีทั้งการแชร์ตำแหน่งกันในรอบ
   การบันทึกเส้นทางเดินป่า และตำแหน่งที่แนบไปกับ SOS — ประโยคที่ไม่จริงใน
   นโยบายความเป็นส่วนตัวคือความเสี่ยงตรง ๆ ไม่ใช่แค่เรื่องภาพลักษณ์
*/
const collected = [
  {
    icon: 'person',
    title: 'ข้อมูลบัญชีและผู้เดินทาง',
    body: 'ชื่อ-นามสกุล อีเมล เบอร์โทร วันเกิด รูปโปรไฟล์ เลขบัตรประชาชนหรือหนังสือเดินทางสำหรับทริปที่ต้องใช้ และผู้ติดต่อฉุกเฉิน',
  },
  {
    icon: 'health_and_safety',
    title: 'ข้อมูลสุขภาพและอาหาร',
    body: 'โรคประจำตัว ยาที่ใช้ อาหารที่แพ้ และความต้องการด้านอาหาร เก็บเพื่อความปลอดภัยหน้างานและการทำประกันภัยการเดินทาง ถือเป็นข้อมูลอ่อนไหวที่เก็บบนฐานความยินยอมของท่าน',
  },
  {
    icon: 'payments',
    title: 'ข้อมูลการจองและการชำระเงิน',
    body: 'ประวัติการจอง ยอดเงิน วิธีชำระ และหลักฐานการโอน เราไม่เก็บเลขบัตรเครดิตของท่านไว้ในระบบ ข้อมูลบัตรอยู่กับผู้ให้บริการรับชำระเงินโดยตรง',
  },
  {
    icon: 'my_location',
    title: 'ข้อมูลตำแหน่ง',
    body: 'ตำแหน่งของท่านถูกบันทึกเมื่อท่านเปิดใช้เองเท่านั้น ได้แก่ การแชร์ตำแหน่งให้เพื่อนร่วมรอบระหว่างทริป การบันทึกเส้นทางเดินป่าของท่าน และตำแหน่งที่แนบไปกับการแจ้งเหตุฉุกเฉิน (SOS) ปิดได้ทุกเมื่อจากในแอป',
  },
  {
    icon: 'photo_camera',
    title: 'รูปภาพและเนื้อหาที่ท่านโพสต์',
    body: 'รูปที่ท่านอัปโหลด รีวิว ข้อความในห้องแชทของรอบเดินทาง และรายงานที่ท่านส่งถึงทีมงาน',
  },
  {
    icon: 'devices',
    title: 'ข้อมูลอุปกรณ์และการใช้งาน',
    body: 'ที่อยู่ IP ประเภทอุปกรณ์ เวอร์ชันแอป โทเคนสำหรับส่งการแจ้งเตือน คุกกี้ และบันทึกข้อขัดข้องของแอป',
  },
]

const purposes = [
  { purpose: 'ดำเนินการจอง จัดที่นั่ง รับชำระเงิน ออกใบเสร็จ และให้บริการตามที่ตกลงไว้', basis: 'การปฏิบัติตามสัญญา' },
  { purpose: 'แจ้งข้อมูลสำคัญของรอบเดินทาง เช่น เวลานัดหมาย การเปลี่ยนแปลง และการแจ้งเตือนวันครบกำหนดชำระ', basis: 'การปฏิบัติตามสัญญา' },
  { purpose: 'ดูแลความปลอดภัยระหว่างเดินทาง รวมถึงการทำประกันภัย การแจ้งเหตุฉุกเฉิน และการแจ้งผู้ติดต่อฉุกเฉินของท่าน', basis: 'ความยินยอม และประโยชน์สำคัญต่อชีวิต' },
  { purpose: 'ป้องกันการทุจริต ตรวจสอบหลักฐานการชำระเงิน และรักษาความปลอดภัยของระบบ', basis: 'ประโยชน์โดยชอบด้วยกฎหมาย' },
  { purpose: 'ปรับปรุงคุณภาพบริการ วิเคราะห์การใช้งานเว็บไซต์และแอปในภาพรวม', basis: 'ความยินยอม (คุกกี้วิเคราะห์)' },
  { purpose: 'ส่งข่าวสาร โปรโมชัน และคำแนะนำทริป', basis: 'ความยินยอม ยกเลิกรับได้ทุกเมื่อ' },
  { purpose: 'จัดทำเอกสารทางบัญชีและภาษี และปฏิบัติตามกฎหมายว่าด้วยธุรกิจนำเที่ยว', basis: 'หน้าที่ตามกฎหมาย' },
]

const recipients = [
  { icon: 'hiking', name: 'ผู้ให้บริการหน้างาน', body: 'ที่พัก อุทยาน สายการบิน บริษัทขนส่ง ลูกหาบ และบริษัทประกันภัย ได้รับเฉพาะรายชื่อและข้อมูลที่จำเป็นต่อรอบเดินทางที่ท่านจอง' },
  { icon: 'credit_card', name: 'ผู้ให้บริการรับชำระเงิน (Beam Checkout)', body: 'ประมวลผลการชำระเงินและการคืนเงิน' },
  { icon: 'document_scanner', name: 'Anthropic (Claude API)', body: 'อ่านข้อมูลจากสลิปโอนเงินที่ท่านอัปโหลดเพื่อจับคู่กับยอดที่ต้องชำระ และประมวลผลคำถามที่ท่านพิมพ์ในผู้ช่วยวางทริป ข้อมูลถูกส่งไปเพื่อประมวลผลตามคำขอนั้นเท่านั้น' },
  { icon: 'sms', name: 'ThaiBulkSMS', body: 'ส่ง SMS แจ้งเตือนและรหัสยืนยันไปยังเบอร์ของท่าน' },
  { icon: 'mail', name: 'Brevo', body: 'ส่งอีเมลยืนยันการจอง ใบเสร็จ และการแจ้งเตือน' },
  { icon: 'cloud', name: 'Cloudflare R2', body: 'จัดเก็บรูปภาพและไฟล์เอกสารที่อัปโหลดเข้าระบบ' },
  { icon: 'notifications', name: 'Google Firebase', body: 'ส่งการแจ้งเตือนเข้าอุปกรณ์ (FCM) วิเคราะห์การใช้งาน และรายงานข้อขัดข้องของแอป' },
  { icon: 'map', name: 'Google Maps Platform', body: 'คำนวณเส้นทางและเวลาถึงจุดรับ-ส่งโดยประมาณ' },
  { icon: 'bug_report', name: 'Sentry', body: 'บันทึกข้อผิดพลาดของระบบเพื่อให้ทีมงานแก้ไขได้' },
  { icon: 'analytics', name: 'Google Analytics และ Meta Pixel', body: 'วัดผลการใช้งานและโฆษณา ทำงานเฉพาะเมื่อท่านกดยอมรับคุกกี้เพื่อการวิเคราะห์และการตลาดเท่านั้น' },
  { icon: 'account_balance', name: 'หน่วยงานของรัฐ', body: 'เฉพาะกรณีที่กฎหมายกำหนดให้เปิดเผย หรือเพื่อการก่อตั้งสิทธิเรียกร้องตามกฎหมาย' },
]

const retention = [
  { what: 'ข้อมูลการจอง การชำระเงิน และเอกสารทางบัญชี', how_long: 'เก็บไว้ 10 ปีตามกฎหมายบัญชีและภาษีของประเทศไทย' },
  { what: 'ข้อมูลบัญชีผู้ใช้', how_long: 'เก็บไว้ตราบที่บัญชียังใช้งานอยู่ และลบเมื่อท่านขอปิดบัญชี เว้นส่วนที่ต้องเก็บตามข้อบน' },
  { what: 'ตำแหน่งที่แชร์ให้เพื่อนร่วมรอบ', how_long: 'แสดงผลเฉพาะหมุดที่ใหม่กว่า 30 นาที และหยุดแชร์เมื่อไหร่ ระบบลบตำแหน่งของท่านทันที' },
  { what: 'รูปภาพประจำรอบเดินทางที่ทีมงานอัปโหลด', how_long: 'ระบบลบอัตโนมัติหลังอัปโหลดครบ 7 วัน' },
  { what: 'ข้อมูลสุขภาพและอาหารที่ท่านแจ้ง', how_long: 'ใช้เฉพาะรอบเดินทางนั้น และลบได้ตามคำขอของท่าน' },
]

const rights = [
  { title: 'สิทธิขอเข้าถึงและขอสำเนา', body: 'ขอดูข้อมูลที่เรามีเกี่ยวกับท่าน' },
  { title: 'สิทธิขอแก้ไข', body: 'แก้ข้อมูลที่ไม่ถูกต้องหรือไม่เป็นปัจจุบัน' },
  { title: 'สิทธิขอลบ', body: 'ขอให้ลบข้อมูลเมื่อหมดความจำเป็นหรือท่านถอนความยินยอม' },
  { title: 'สิทธิขอให้ระงับการใช้', body: 'ให้เราหยุดใช้ข้อมูลชั่วคราวระหว่างตรวจสอบ' },
  { title: 'สิทธิคัดค้านการประมวลผล', body: 'คัดค้านการใช้ข้อมูลเพื่อการตลาดหรือประโยชน์โดยชอบด้วยกฎหมาย' },
  { title: 'สิทธิขอให้โอนย้ายข้อมูล', body: 'ขอรับข้อมูลในรูปแบบที่อ่านด้วยเครื่องได้' },
  { title: 'สิทธิถอนความยินยอม', body: 'ถอนได้ทุกเมื่อ โดยไม่กระทบการประมวลผลที่ทำไปแล้ว' },
  { title: 'สิทธิร้องเรียน', body: 'ร้องเรียนต่อสำนักงานคณะกรรมการคุ้มครองข้อมูลส่วนบุคคล (สคส.)' },
]

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

@media (prefers-reduced-motion: reduce) {
  .privacy-page { animation: none; }
}
</style>
