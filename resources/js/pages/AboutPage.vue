<template>
  <div ref="root" class="about-page font-anuphan bg-[var(--color-sand)]">

    <PageHero
      image="/images/phusoidao.webp"
      badge="เกี่ยวกับเรา"
      badge-icon="landscape"
      title="เปลี่ยนความอยากเที่ยว เป็นการเดินทางที่ง่ายที่สุด"
      subtitle="รวมทริปที่คัดมาแล้ว จองได้เองในไม่กี่ขั้นตอน และรู้ผลทันที เหมือนมีเพื่อนสนิทช่วยวางแผนให้"
    >
      <template #actions>
        <router-link to="/trips" class="hero-btn hero-btn--solid">
          ค้นหาทริป
          <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
        </router-link>
        <a href="#our-story" class="hero-btn hero-btn--outline" @click.prevent="scrollTo('our-story')">ทำความรู้จักเรา</a>
      </template>
    </PageHero>

    <!-- ══════════════════════════════════════════
         OUR STORY
    ══════════════════════════════════════════ -->
    <section id="our-story" class="scroll-mt-20 py-16 md:py-24">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-16 items-center">
          <div class="lg:col-span-7 reveal">
            <SectionHeading eyebrow="จุดเริ่มต้นของเรา">เราเริ่มต้นจากความรักในการเดินทาง</SectionHeading>
            <div class="mt-6 space-y-5 text-base md:text-lg text-[var(--color-text-mid)] leading-relaxed">
              <p>
                เราคือกลุ่มคนที่หลงรักเสียงคลื่นและตกหลุมรักสายหมอก เชื่อว่าหัวใจของการเดินทาง
                คือการได้ออกไปเห็นโลกกว้าง สัมผัสอากาศบริสุทธิ์ และเก็บความทรงจำใหม่ ๆ
              </p>
              <p>
                แต่เราก็รู้ดีว่า บ่อยครั้งที่ "ความอยากไป" ต้องจบลงที่ "ความยุ่งยาก"
                ทั้งการรอแอดมินตอบ ข้อมูลทริปที่ไม่ชัดเจน หรือการจองที่ซับซ้อน เราจึงถามตัวเองง่าย ๆ ว่า
              </p>
            </div>
            <blockquote class="mt-8 rounded-2xl border-l-4 border-[var(--color-accent)] bg-white px-6 py-5">
              <p class="text-xl md:text-2xl font-bold text-[var(--color-text-dark)] leading-snug">
                “ทำไมการออกไปพบเจอความสุข มันถึงต้องเป็นเรื่องยากขนาดนี้?”
              </p>
            </blockquote>
          </div>
          <div class="lg:col-span-5 reveal" style="--reveal-delay: 0.1s">
            <img
              src="/images/abouts_luilaykhao.JPG"
              alt="บรรยากาศระหว่างทริปของลุยเลเขา"
              loading="lazy"
              class="w-full aspect-[4/3] lg:aspect-[4/5] object-cover rounded-3xl"
            />
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         WHY WE BUILT THIS
    ══════════════════════════════════════════ -->
    <section class="bg-white border-y border-[var(--color-sand-dark)] py-16 md:py-24">
      <div class="max-w-3xl mx-auto px-4 sm:px-6 md:px-8 text-center reveal">
        <SectionHeading eyebrow="ทำไมเราถึงสร้างสิ่งนี้" tone="gold" center>
          เราสร้างที่นี่ เพื่อให้การออกไปเห็นโลก
          <span class="text-[var(--color-accent)]">เป็นเรื่องที่ง่ายที่สุด</span>
        </SectionHeading>
        <p class="mt-6 text-lg md:text-xl text-[var(--color-text-mid)] leading-relaxed">
          ไม่ต้องรอคำตอบที่เนิ่นนาน ไม่ต้องสับสนกับตารางที่ดูยาก
          แค่คุณเริ่ม "อยากไป" ระบบของเราก็พร้อมพาคุณ "ไปได้ทันที"
        </p>
        <p class="mt-4 text-base text-[var(--color-text-muted)] leading-relaxed">
          เราดูแลตั้งแต่ระบบจองไปจนถึงการคัดพาร์ทเนอร์ทริปที่ไว้ใจได้ เพื่อให้เวลาที่คุณอยู่กับเราเป็นจุดเริ่มต้นของทริปที่ดี
        </p>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         PILLARS
    ══════════════════════════════════════════ -->
    <section class="py-16 md:py-24">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
        <div class="reveal">
          <SectionHeading eyebrow="หลักการของเรา" title="สิ่งที่เราตั้งใจทำ" />
        </div>
        <div class="mt-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-5">
          <IllustratedCard
            v-for="(item, i) in pillars"
            :key="item.title"
            v-bind="item"
            class="reveal"
            :style="{ '--reveal-delay': `${i * 0.08}s` }"
          />
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         STATS — ตัวเลขจริงจากฐานข้อมูลเท่านั้น โหลดไม่ได้ก็ไม่วาด
    ══════════════════════════════════════════ -->
    <section v-if="stats.length" class="pb-16 md:pb-24">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
        <div
          class="reveal grid rounded-3xl border border-[var(--color-sand-dark)] bg-white divide-y sm:divide-y-0 sm:divide-x divide-[var(--color-sand-dark)]"
          :class="STAT_COLS[stats.length] || 'sm:grid-cols-4'"
        >
          <div v-for="stat in stats" :key="stat.key" class="px-6 py-7 text-center sm:text-left">
            <span class="material-symbols-rounded text-[24px] text-[var(--color-accent)]" :style="stat.key === 'rating' ? FILLED : ''">{{ stat.icon }}</span>
            <p class="mt-2 text-3xl md:text-4xl font-extrabold text-[var(--color-text-dark)] tabular-nums leading-none">{{ stat.display }}</p>
            <p class="mt-2 text-[13px] font-semibold text-[var(--color-text-muted)]">{{ stat.label }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         VALUES
    ══════════════════════════════════════════ -->
    <section class="bg-white border-y border-[var(--color-sand-dark)] py-16 md:py-24">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
        <div class="reveal">
          <SectionHeading eyebrow="คุณค่าของเรา" title="สิ่งที่เราเชื่อมั่น" />
        </div>
        <div class="mt-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-5">
          <IllustratedCard
            v-for="(item, i) in values"
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
          <span class="material-symbols-rounded pointer-events-none absolute -top-6 right-4 md:right-10 text-[160px] md:text-[220px] leading-none text-white/[0.06] select-none" aria-hidden="true">handshake</span>
          <div class="relative max-w-3xl">
            <h2 class="text-3xl md:text-5xl font-extrabold text-white leading-tight tracking-tight">
              เราไม่ได้อยากเป็นแค่<span class="text-[var(--color-gold)]">แพลตฟอร์มจองทริป</span>
            </h2>
            <p class="mt-5 text-lg md:text-xl text-white/75 leading-relaxed">
              แต่อยากเป็น<strong class="text-white">เพื่อน</strong>ที่ช่วยให้คุณออกไปเที่ยวได้ง่ายขึ้น และมีความสุขมากขึ้นในทุกการเดินทางที่เลือกไปกับเรา
            </p>
            <div class="mt-9 flex flex-wrap items-center gap-3">
              <router-link to="/trips" class="hero-btn hero-btn--solid">
                ดูทริปที่เปิดรับ
                <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
              </router-link>
              <router-link to="/goal" class="hero-btn hero-btn--outline">จุดมุ่งหมายของเรา</router-link>
            </div>
          </div>
        </div>
      </div>
    </section>

  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import IllustratedCard from '../components/IllustratedCard.vue'
import PageHero from '../components/PageHero.vue'
import SectionHeading from '../components/SectionHeading.vue'
import { fetchCommunityStats } from '../lib/communityStats'
import { useReveal } from '../lib/useReveal'

const FILLED = "font-variation-settings:'FILL' 1"
const STAT_COLS = { 1: 'sm:grid-cols-1', 2: 'sm:grid-cols-2', 3: 'sm:grid-cols-3', 4: 'sm:grid-cols-4' }

const root = ref(null)
useReveal(root)

// ว่างไว้ก่อนโดยตั้งใจ — เติมจาก /stats เมื่อได้ตัวเลขจริง ถ้าเรียกไม่สำเร็จก็ไม่แสดงแถบนี้เลย
const stats = ref([])

const pillars = [
  { image: '/images/abouts_ready.webp', title: 'ไม่ต้องรอแอดมิน', desc: 'เห็นที่นั่งว่างตามจริง และจองได้เองตลอด 24 ชั่วโมง ไม่ต้องรอใครตอบ' },
  { image: '/images/abouts_buddy.webp', title: 'เพื่อนร่วมทางตัวจริง', desc: 'เราไม่ได้ทำแค่ระบบจอง แต่ดูแลคุณตั้งแต่เริ่มวางแผน จนกลับถึงบ้าน' },
  { image: '/images/abouts_happy.webp', title: 'สุขใจทุกกิโลเมตร', desc: 'ทุกทริปคัดมาให้ได้พักผ่อนและชาร์จพลังเต็มที่ ไม่ใช่แค่ไปให้ถึง' },
]

const values = [
  { image: '/images/travel_safety.webp', title: 'ความปลอดภัยมาก่อน', desc: 'ตรวจสภาพรถและอุปกรณ์ก่อนออกเดินทางทุกรอบ มีทีมงานดูแลตลอดทาง และมีปุ่มแจ้งเหตุฉุกเฉินในแอป' },
  { image: '/images/nohidden_show.webp', title: 'ราคาโปร่งใส', desc: 'ไม่มีค่าใช้จ่ายแอบแฝง เห็นยอดรวมสุทธิก่อนกดชำระเงินทุกครั้ง' },
  { image: '/images/nature_travel.webp', title: 'ใส่ใจชุมชน', desc: 'ทำงานร่วมกับคนในท้องถิ่น เพื่อกระจายรายได้และรักษาธรรมชาติให้อยู่ได้นาน' },
  { image: '/images/247_support.webp', title: 'ดูแลตลอดทริป', desc: 'ทีมงานพร้อมช่วยตลอดการเดินทาง ให้คุณอุ่นใจเหมือนมีเพื่อนอยู่ข้าง ๆ' },
]

function scrollTo(id) {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  document.getElementById(id)?.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' })
}

onMounted(async () => {
  stats.value = await fetchCommunityStats()
})
</script>
