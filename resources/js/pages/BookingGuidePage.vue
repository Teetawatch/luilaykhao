<template>
  <div ref="root" class="booking-guide-page font-anuphan bg-[var(--color-sand)] min-h-screen">

    <PageHero
      image="/images/landscape.webp"
      badge="วิธีการจอง"
      badge-icon="menu_book"
      :title="content.hero_title"
      :subtitle="content.hero_subtitle"
      :loading="loading"
    >
      <template #actions>
        <router-link to="/trips" class="hero-btn hero-btn--solid">
          {{ content.cta_label || 'ดูทริปที่เปิดรับ' }}
          <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
        </router-link>
        <router-link to="/faq" class="hero-btn hero-btn--outline">คำถามที่พบบ่อย</router-link>
      </template>
    </PageHero>

    <section class="py-16 md:py-24">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-16">

          <header class="lg:col-span-4 reveal">
            <div class="lg:sticky lg:top-28">
              <SectionHeading eyebrow="ขั้นตอนการจอง" :title="steps.length ? `${steps.length} ขั้นตอน ก็พร้อมออกเดินทาง` : 'ขั้นตอนการจอง'" />
              <p class="mt-4 text-[15px] text-[var(--color-text-mid)] leading-relaxed">
                จองได้เองทั้งบนเว็บและในแอป ที่นั่งจะถูกกันไว้ให้ระหว่างทำรายการ
              </p>
            </div>
          </header>

          <!-- Steps -->
          <div class="lg:col-span-8">
            <ol v-if="steps.length" class="relative">
              <li
                v-for="(step, index) in steps"
                :key="index"
                class="step reveal relative flex gap-5 pb-8 last:pb-0"
                :style="{ '--reveal-delay': `${Math.min(index, 4) * 0.06}s` }"
              >
                <!-- เส้นเชื่อมระหว่างขั้น -->
                <span v-if="index < steps.length - 1" class="absolute left-6 top-14 bottom-2 w-px bg-[var(--color-sand-dark)]" aria-hidden="true"></span>
                <span class="relative z-10 w-12 h-12 shrink-0 rounded-2xl bg-[var(--color-primary)] text-white flex items-center justify-center text-lg font-extrabold tabular-nums">
                  {{ index + 1 }}
                </span>
                <div class="flex-1 min-w-0 rounded-3xl border border-[var(--color-sand-dark)] bg-white p-5 md:p-6">
                  <h2 class="text-lg md:text-xl font-extrabold text-[var(--color-text-dark)]">{{ step.title }}</h2>
                  <p class="mt-2 text-[15px] text-[var(--color-text-mid)] leading-relaxed whitespace-pre-line">{{ step.detail }}</p>
                </div>
              </li>
            </ol>

            <div v-else-if="loading" class="space-y-4" aria-hidden="true">
              <div v-for="n in 4" :key="n" class="flex gap-5">
                <div class="w-12 h-12 rounded-2xl bg-white border border-[var(--color-sand-dark)] animate-pulse shrink-0"></div>
                <div class="flex-1 h-28 rounded-3xl bg-white border border-[var(--color-sand-dark)] animate-pulse"></div>
              </div>
            </div>

            <!-- Help -->
            <div v-if="content.help_title" class="reveal mt-10 rounded-3xl bg-[var(--color-primary)] p-6 md:p-8 text-white">
              <div class="flex flex-col md:flex-row md:items-center gap-5">
                <span class="w-12 h-12 rounded-2xl bg-white/10 flex items-center justify-center shrink-0">
                  <span class="material-symbols-rounded text-[26px] text-[var(--color-accent-light)]">support_agent</span>
                </span>
                <div class="flex-1">
                  <h2 class="text-xl font-extrabold">{{ content.help_title }}</h2>
                  <p class="mt-1 text-[15px] text-white/75 leading-relaxed whitespace-pre-line">{{ content.help_detail }}</p>
                </div>
              </div>
              <div class="mt-6 flex flex-wrap gap-2">
                <a :href="supportLineUrl()" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold text-[var(--color-primary)] hover:bg-[var(--color-sand)] transition-colors">
                  <span class="material-symbols-rounded text-[18px]">chat</span>
                  LINE {{ supportLine() }}
                </a>
                <a :href="supportPhoneHref()" class="inline-flex items-center gap-2 rounded-xl border border-white/25 px-5 py-3 text-sm font-bold text-white hover:bg-white/10 transition-colors">
                  <span class="material-symbols-rounded text-[18px]">call</span>
                  {{ supportPhone() }}
                </a>
                <router-link to="/contact" class="inline-flex items-center gap-2 rounded-xl px-4 py-3 text-sm font-bold text-white/80 hover:text-white transition-colors">
                  ส่งข้อความหาเรา
                </router-link>
              </div>
            </div>

            <!-- Related -->
            <div class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-3">
              <router-link v-for="link in related" :key="link.to" :to="link.to" class="related-link reveal">
                <span class="material-symbols-rounded text-[22px] text-[var(--color-accent)]">{{ link.icon }}</span>
                <span class="flex-1 min-w-0">
                  <span class="block text-sm font-bold text-[var(--color-text-dark)]">{{ link.label }}</span>
                  <span class="block text-[12px] text-[var(--color-text-muted)] truncate">{{ link.desc }}</span>
                </span>
                <span class="material-symbols-rounded text-[18px] text-[var(--color-text-muted)]">chevron_right</span>
              </router-link>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import PageHero from '../components/PageHero.vue'
import SectionHeading from '../components/SectionHeading.vue'
import { usePageContent } from '../lib/pageContent'
import { useReveal } from '../lib/useReveal'
import { supportLine, supportLineUrl, supportPhone, supportPhoneHref } from '../lib/contact'

const root = ref(null)
useReveal(root)

const { content, loading } = usePageContent('booking_guide', {
  hero_title: '',
  hero_subtitle: '',
  steps: [],
  help_title: '',
  help_detail: '',
  cta_label: '',
})

const steps = computed(() => (content.value.steps || []).filter((s) => s?.title))

const related = [
  { to: '/faq', icon: 'quiz', label: 'คำถามที่พบบ่อย', desc: 'การจอง ชำระเงิน การเดินทาง' },
  { to: '/terms', icon: 'gavel', label: 'เงื่อนไขการให้บริการ', desc: 'การยกเลิก เลื่อน และคืนเงิน' },
  { to: '/checklist', icon: 'checklist', label: 'ของที่ควรเตรียม', desc: 'เช็กลิสต์ก่อนออกเดินทาง' },
]
</script>

<style scoped>
.related-link {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  border-radius: 1.25rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  padding: 1rem;
  transition: border-color 0.2s ease;
}
.related-link:hover { border-color: var(--color-accent); }
</style>
