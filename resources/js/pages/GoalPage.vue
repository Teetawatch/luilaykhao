<template>
  <div ref="root" class="goal-page font-anuphan bg-[var(--color-sand)]">

    <PageHero
      image="/images/hiking.webp"
      badge="จุดมุ่งหมาย"
      badge-icon="flag"
      title="ทำให้การท่องเที่ยวธรรมชาติ เป็นเรื่องง่ายสำหรับทุกคน"
      subtitle="เพราะเราเชื่อว่าการผจญภัยที่ดี ไม่ควรถูกขัดจังหวะด้วยความยุ่งยากในการจองและข้อมูลที่สับสน"
    >
      <!-- ตัวเลขจริงจาก /stats — ไม่มีก็ไม่โชว์ (เดิมขึ้น "0+" และ "5.0 ดาว" ทั้งที่ยังไม่มีรีวิว) -->
      <dl v-if="heroStats.length" class="mt-9 flex flex-wrap gap-x-10 gap-y-5 border-t border-white/15 pt-6">
        <div v-for="stat in heroStats" :key="stat.key">
          <dt class="sr-only">{{ stat.label }}</dt>
          <dd class="flex items-center gap-1.5 text-3xl font-extrabold text-white tabular-nums leading-none">
            {{ stat.display }}
            <span v-if="stat.key === 'rating'" class="material-symbols-rounded text-[24px] text-[var(--color-gold)]" :style="FILLED">star</span>
          </dd>
          <dd class="mt-1.5 text-[13px] font-semibold text-white/65" aria-hidden="true">{{ stat.label }}</dd>
        </div>
      </dl>

      <template #actions>
        <router-link to="/trips" class="hero-btn hero-btn--solid">
          ดูทริปที่เปิดรับ
          <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
        </router-link>
        <router-link to="/problem" class="hero-btn hero-btn--outline">ปัญหาที่เราอยากแก้</router-link>
      </template>
    </PageHero>

    <!-- ══════════════════════════════════════════
         OUR STORY
    ══════════════════════════════════════════ -->
    <section class="py-16 md:py-24">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-16 items-center">
          <div class="lg:col-span-7 order-2 lg:order-1 reveal">
            <SectionHeading eyebrow="จุดเริ่มต้นของเรา">จากความเจ็บปวด สู่ความตั้งใจที่จะเปลี่ยน</SectionHeading>
            <div class="mt-6 space-y-5 text-base md:text-lg text-[var(--color-text-mid)] leading-relaxed">
              <p>เราไม่ได้เริ่มจากการอยากสร้างธุรกิจใหญ่ แต่เริ่มจาก "ความหงุดหงิด" ที่เจอในฐานะนักเดินทางเหมือนกับคุณ</p>
              <p>เราเคยติดอยู่กับข้อมูลทริปที่กระจัดกระจาย การจองที่ต้องรอแอดมินตอบเป็นวัน ๆ และความไม่มั่นใจว่าทริปที่จองไปจะเป็นอย่างไร</p>
              <p>ลุยเลเขาจึงถูกสร้างขึ้น เพื่อให้คุณไม่ต้องพึ่ง "โชค" ในการไปเที่ยว แต่วางแผนและออกเดินทางได้ทันทีที่พร้อม</p>
            </div>
            <blockquote class="mt-8 rounded-2xl border-l-4 border-[var(--color-accent)] bg-white px-6 py-5">
              <p class="text-xl md:text-2xl font-bold text-[var(--color-text-dark)] leading-snug">
                “ช่วงเวลาที่ดีที่สุดในการออกไปเห็นโลก คือตอนที่คุณอยากไปที่สุด”
              </p>
            </blockquote>
          </div>

          <div class="lg:col-span-5 order-1 lg:order-2 reveal" style="--reveal-delay: 0.1s">
            <figure class="relative">
              <img
                src="/images/ceoluilaykhao.webp"
                alt="ผู้ก่อตั้งลุยเลเขา"
                loading="lazy"
                class="w-full aspect-[4/3] lg:aspect-[4/5] object-cover rounded-3xl"
              />
              <figcaption class="absolute left-4 bottom-4 right-4 sm:right-auto flex items-center gap-3 rounded-2xl bg-white px-4 py-3">
                <span class="w-10 h-10 rounded-xl bg-[var(--color-accent)]/10 flex items-center justify-center shrink-0">
                  <span class="material-symbols-rounded text-[22px] text-[var(--color-accent)]" :style="FILLED">favorite</span>
                </span>
                <span>
                  <span class="block text-[11px] font-bold text-[var(--color-text-muted)]">Travel with Heart</span>
                  <span class="block text-[15px] font-extrabold text-[var(--color-text-dark)]">ใส่ใจทุกย่างก้าว</span>
                </span>
              </figcaption>
            </figure>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         PART OF YOUR JOURNEY
    ══════════════════════════════════════════ -->
    <section class="bg-white border-y border-[var(--color-sand-dark)] py-16 md:py-24">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
        <div class="reveal">
          <SectionHeading eyebrow="ไม่ใช่แค่แพลตฟอร์ม แต่คือเพื่อนร่วมทาง" tone="gold">
            เราอยากเป็น<span class="text-[var(--color-accent)]">ส่วนหนึ่ง</span>ที่ช่วยให้คุณออกไปเที่ยวได้ง่ายขึ้น
          </SectionHeading>
        </div>
        <div class="mt-10 grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-5">
          <div
            v-for="(item, i) in journey"
            :key="item.title"
            class="reveal flex gap-5 rounded-3xl bg-[var(--color-sand)] p-6 md:p-7"
            :style="{ '--reveal-delay': `${i * 0.08}s` }"
          >
            <span class="w-12 h-12 rounded-2xl bg-white flex items-center justify-center shrink-0" :class="item.tone">
              <span class="material-symbols-rounded text-[26px]">{{ item.icon }}</span>
            </span>
            <div>
              <h3 class="text-xl font-extrabold text-[var(--color-text-dark)]">{{ item.title }}</h3>
              <p class="mt-2 text-[15px] text-[var(--color-text-mid)] leading-relaxed">{{ item.desc }}</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         WHAT WE GIVE
    ══════════════════════════════════════════ -->
    <section class="py-16 md:py-24">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
        <div class="reveal">
          <SectionHeading eyebrow="ค่านิยมที่เรายึดถือ" title="สิ่งที่เราตั้งใจมอบให้คุณ" />
        </div>
        <div class="mt-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-5">
          <IllustratedCard
            v-for="(item, i) in goals"
            :key="item.title"
            v-bind="item"
            class="reveal"
            :style="{ '--reveal-delay': `${i * 0.08}s` }"
          />
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         LONG-TERM VISION
    ══════════════════════════════════════════ -->
    <section class="bg-white border-y border-[var(--color-sand-dark)] py-16 md:py-24">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
        <div class="reveal">
          <SectionHeading eyebrow="วิสัยทัศน์ในอนาคต" title="อนาคตการท่องเที่ยวที่เราสร้างร่วมกัน" />
        </div>
        <div class="mt-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-5">
          <IllustratedCard
            v-for="(item, i) in visions"
            :key="item.title"
            v-bind="item"
            sand
            small
            class="reveal"
            :style="{ '--reveal-delay': `${i * 0.08}s` }"
          />
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         CLOSING
    ══════════════════════════════════════════ -->
    <section class="py-16 md:py-24">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
        <div class="reveal relative overflow-hidden rounded-[2rem] bg-[var(--color-primary)] px-6 py-12 sm:px-10 md:px-16 md:py-16">
          <span class="material-symbols-rounded pointer-events-none absolute -top-6 right-4 md:right-10 text-[160px] md:text-[220px] leading-none text-white/[0.06] select-none" aria-hidden="true">verified</span>
          <div class="relative max-w-3xl">
            <h2 class="text-3xl md:text-5xl font-extrabold text-white leading-tight tracking-tight">
              การท่องเที่ยวที่คุณ<span class="text-[var(--color-gold)]">วางใจได้</span>ในทุกคลิก
            </h2>
            <p class="mt-5 text-lg md:text-xl text-white/75 leading-relaxed">
              ลบภาพการจองทริปที่วุ่นวายออกไป แล้วเปลี่ยนความกังวลให้เป็นความตื่นเต้นที่จะได้ออกไปเจอโลกกว้าง
            </p>
            <div class="mt-9 flex flex-wrap items-center gap-3">
              <router-link to="/trips" class="hero-btn hero-btn--solid">
                เริ่มหาทริป
                <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
              </router-link>
              <router-link to="/how-to-book" class="hero-btn hero-btn--outline">ดูวิธีการจอง</router-link>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import IllustratedCard from '../components/IllustratedCard.vue'
import PageHero from '../components/PageHero.vue'
import SectionHeading from '../components/SectionHeading.vue'
import { fetchCommunityStats } from '../lib/communityStats'
import { useReveal } from '../lib/useReveal'

const FILLED = "font-variation-settings:'FILL' 1"

const root = ref(null)
useReveal(root)

const stats = ref([])
const heroStats = computed(() => stats.value.filter((s) => ['travellers', 'rounds', 'rating'].includes(s.key)))

const journey = [
  { icon: 'map', tone: 'text-[var(--color-accent)]', title: 'วางแผนให้เสร็จในที่เดียว', desc: 'ไม่ต้องเปิดหาข้อมูลหลายเว็บหรือรอแอดมินตอบเป็นชั่วโมง เราคัดข้อมูลทริป เส้นทาง และราคาที่โปร่งใสมาให้เลือกได้ทันที' },
  { icon: 'support_agent', tone: 'text-[var(--color-gold)]', title: 'อุ่นใจเหมือนมีเพื่อนไปด้วย', desc: 'ไม่ว่าจะเป็นทริปเดินป่าครั้งแรก หรือจุดหมายที่ไม่คุ้นเคย ทีมงานและพาร์ทเนอร์ของเราดูแลคุณตั้งแต่ก้าวแรก' },
]

const goals = [
  { image: '/images/fast_nowaitng.webp', title: 'จองทริปได้ในไม่กี่คลิก', desc: 'ไม่ต้องเสียเวลาหาข้อมูล ไม่ต้องรอแอดมินตอบกลับ อยากไปวันไหนก็เลือกได้ทันที' },
  { image: '/images/feel_good.webp', title: 'ดูแลคุณตลอดเส้นทาง', desc: 'ไม่ใช่แค่การจอง แต่เป็นเพื่อนร่วมทางที่พร้อมช่วยจนถึงจุดหมายอย่างปลอดภัย' },
  { image: '/images/no_wating.webp', title: 'เริ่มผจญภัยได้ทุกคน', desc: 'มีทริปที่ออกแบบให้เหมาะ ตั้งแต่นักเดินป่ามือใหม่ไปจนถึงสายลุยระดับโปร' },
]

const visions = [
  { image: '/images/no_wating.webp', title: 'เที่ยวได้ทันที', desc: 'ไม่ต้องรอคิว ไม่ต้องรอแอดมิน จัดการทุกอย่างได้เอง' },
  { image: '/images/no_hassle.webp', title: 'จองง่ายใน 1 นาที', desc: 'เรียบง่ายที่สุด ให้เวลาของคุณไปอยู่กับการเตรียมตัวเที่ยว ไม่ใช่การจอง' },
  { image: '/images/nohidden_show.webp', title: 'ราคาใสสะอาด', desc: 'สิ่งที่เห็นคือสิ่งที่จ่าย ไม่มีค่าบริการแฝงให้ปวดหัว' },
  { image: '/images/no_lucky.webp', title: 'มั่นใจได้ทุกทริป', desc: 'ระบบที่เชื่อถือได้พร้อมทีมงานดูแล ให้ทุกการเดินทางราบรื่น' },
]

onMounted(async () => {
  stats.value = await fetchCommunityStats()
})
</script>
