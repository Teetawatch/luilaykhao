<template>
  <div class="problem-page font-anuphan bg-[var(--color-sand)]">

    <!-- ══════════════════════════════════════════
         HERO SECTION
    ══════════════════════════════════════════ -->
    <section class="relative overflow-hidden bg-[var(--color-primary)]">
      <div class="absolute inset-0">
        <img
          src="/images/about.jpg"
          alt=""
          class="w-full h-full object-cover opacity-45"
        />
        <div class="absolute inset-0 bg-gradient-to-r from-[var(--color-primary)] via-[var(--color-primary)]/85 to-[var(--color-primary)]/30"></div>
      </div>

      <div class="hero-content relative z-10 max-w-6xl mx-auto px-4 sm:px-6 md:px-8 pt-20 pb-16 md:pt-28 md:pb-24">
        <div class="max-w-2xl">
          <span class="inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3.5 py-1.5 text-[13px] font-bold text-white/90">
            <span class="material-symbols-rounded text-[18px] text-[var(--color-accent-light)]">flag</span>
            เรื่องที่เราตั้งใจแก้
          </span>

          <template v-if="content.hero_title">
            <h1 class="mt-6 text-4xl sm:text-5xl md:text-6xl font-extrabold text-white leading-[1.15] tracking-tight">
              {{ content.hero_title }}
            </h1>
            <p v-if="content.hero_subtitle" class="mt-5 text-lg md:text-xl text-white/80 leading-relaxed">
              {{ content.hero_subtitle }}
            </p>
          </template>
          <div v-else-if="loading" class="mt-6 space-y-4" aria-hidden="true">
            <div class="h-12 md:h-14 w-4/5 rounded-xl bg-white/10 animate-pulse"></div>
            <div class="h-6 w-3/5 rounded-lg bg-white/10 animate-pulse"></div>
          </div>

          <div class="mt-9 flex flex-wrap items-center gap-3">
            <a
              href="#problems"
              class="inline-flex items-center gap-2 rounded-2xl bg-white px-6 py-3.5 text-[15px] font-bold text-[var(--color-primary)] transition-colors hover:bg-[var(--color-sand)] focus:outline-none focus-visible:ring-4 focus-visible:ring-white/40"
              @click.prevent="scrollToProblems"
            >
              ดูปัญหาทั้งหมด
              <span class="material-symbols-rounded text-[20px]">arrow_downward</span>
            </a>
            <router-link
              to="/goal"
              class="inline-flex items-center gap-2 rounded-2xl border border-white/30 px-6 py-3.5 text-[15px] font-bold text-white transition-colors hover:bg-white/10 focus:outline-none focus-visible:ring-4 focus-visible:ring-white/30"
            >
              เป้าหมายของเรา
            </router-link>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         PROBLEMS SECTION
    ══════════════════════════════════════════ -->
    <section id="problems" class="scroll-mt-20 py-16 md:py-24">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-16">

          <!-- หัวข้อ — ค้างอยู่ทางซ้ายบนจอใหญ่ระหว่างเลื่อนดูรายการ -->
          <header class="lg:col-span-4 reveal-section">
            <div class="lg:sticky lg:top-28">
              <p class="inline-flex items-center gap-2 text-[13px] font-bold text-[var(--color-accent)]">
                <span class="w-6 h-[2px] rounded-full bg-[var(--color-accent)]"></span>
                {{ content.section_eyebrow }}
              </p>
              <h2 class="mt-3 text-3xl md:text-4xl font-extrabold text-[var(--color-text-dark)] leading-tight tracking-tight">
                {{ content.section_title }}
              </h2>
              <div v-if="problems.length" class="mt-8 flex items-end gap-3">
                <span class="text-6xl font-extrabold leading-none text-[var(--color-text-dark)] tabular-nums">{{ problems.length }}</span>
                <span class="pb-1.5 text-sm font-bold text-[var(--color-text-muted)] leading-snug">
                  อุปสรรคที่ทำให้<br />ทริปไม่ได้เกิดขึ้น
                </span>
              </div>
            </div>
          </header>

          <!-- รายการปัญหา -->
          <div class="lg:col-span-8">
            <ol v-if="problems.length" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <li
                v-for="(problem, index) in problems"
                :key="index"
                class="problem-card reveal-section group relative flex flex-col rounded-3xl border border-[var(--color-sand-dark)] bg-white p-6 md:p-7"
                :style="{ '--tone': toneOf(problem), '--reveal-delay': `${(index % 2) * 80}ms` }"
              >
                <div class="flex items-start justify-between gap-4">
                  <span class="problem-icon flex h-12 w-12 items-center justify-center rounded-2xl transition-colors duration-300">
                    <span class="material-symbols-rounded text-[26px]">{{ problem.icon || 'help' }}</span>
                  </span>
                  <span class="text-sm font-extrabold tabular-nums text-[var(--color-sand-dark)] transition-colors duration-300 group-hover:text-[var(--tone)]">
                    {{ String(index + 1).padStart(2, '0') }}
                  </span>
                </div>
                <h3 class="mt-5 text-lg font-extrabold text-[var(--color-text-dark)] leading-snug">{{ problem.title }}</h3>
                <p v-if="problem.desc" class="mt-2 text-[15px] text-[var(--color-text-mid)]/80 leading-relaxed">{{ problem.desc }}</p>
              </li>
            </ol>

            <div v-else-if="loading" class="grid grid-cols-1 sm:grid-cols-2 gap-4" aria-hidden="true">
              <div v-for="n in 4" :key="n" class="h-44 rounded-3xl border border-[var(--color-sand-dark)] bg-white animate-pulse"></div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         QUOTE SECTION
    ══════════════════════════════════════════ -->
    <section v-if="quote" class="pb-16 md:pb-24">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
        <div class="reveal-section relative overflow-hidden rounded-[2rem] bg-[var(--color-primary)] px-6 py-12 sm:px-10 md:px-16 md:py-16">
          <span class="material-symbols-rounded pointer-events-none absolute -top-6 right-4 md:right-10 text-[160px] md:text-[220px] leading-none text-white/[0.06] select-none" aria-hidden="true">format_quote</span>

          <div class="relative max-w-3xl">
            <p v-if="content.quote_eyebrow" class="inline-flex items-center gap-2 text-[13px] font-bold text-[var(--color-gold)]">
              <span class="w-6 h-[2px] rounded-full bg-[var(--color-gold)]"></span>
              {{ content.quote_eyebrow }}
            </p>
            <blockquote class="mt-5 text-2xl sm:text-3xl md:text-4xl font-extrabold text-white leading-snug tracking-tight">
              “{{ quote }}”
            </blockquote>

            <div v-if="content.cta_label" class="mt-10 flex flex-wrap items-center gap-3">
              <router-link
                to="/goal"
                class="inline-flex items-center gap-2 rounded-2xl bg-[var(--color-accent-light)] px-6 py-3.5 text-[15px] font-bold text-[var(--color-primary)] transition-colors hover:bg-white focus:outline-none focus-visible:ring-4 focus-visible:ring-[var(--color-accent-light)]/40"
              >
                {{ content.cta_label }}
                <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
              </router-link>
              <router-link
                to="/trips"
                class="inline-flex items-center gap-2 rounded-2xl px-5 py-3.5 text-[15px] font-bold text-white/80 transition-colors hover:text-white"
              >
                ดูทริปที่เปิดรับ
              </router-link>
            </div>
          </div>
        </div>
      </div>
    </section>

  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, watch } from 'vue'
import { usePageContent } from '../lib/pageContent';

const { content, loading } = usePageContent('problem', {
  hero_title: '',
  hero_subtitle: '',
  section_eyebrow: '',
  section_title: '',
  problems: [],
  quote_eyebrow: '',
  quote: '',
  cta_label: '',
})

const problems = computed(() => (content.value.problems || []).filter((p) => p?.title))

/** ค่าเริ่มต้นใส่เครื่องหมายคำพูดมาในตัวบทสรุปเอง — ตัดออกเพราะหน้าใส่ให้แล้ว */
const quote = computed(() => (content.value.quote || '').trim().replace(/^["“”']+|["“”']+$/g, '').trim())

/** สีไอคอนมาจากตัวเลือกสีของแอดมิน ถ้าว่างหรือไม่ใช่ hex ใช้สีหลักแทน */
function toneOf(problem) {
  return /^#[0-9a-f]{3,8}$/i.test(problem?.color || '') ? problem.color : 'var(--color-accent)'
}

function scrollToProblems() {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  document.getElementById('problems')?.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' })
}

let observer = null

function observeReveals() {
  if (!observer) return

  document.querySelectorAll('.problem-page .reveal-section:not(.is-visible)').forEach((el) => observer.observe(el))
}

onMounted(() => {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return

  observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible')
          observer.unobserve(entry.target)
        }
      })
    },
    { threshold: 0.1, rootMargin: "0px 0px -50px 0px" }
  )

  observeReveals()
})

onBeforeUnmount(() => observer?.disconnect())

/** การ์ดปัญหาเพิ่งมีตัวตนหลังโหลดเนื้อหาเสร็จ ต้องสั่ง observe ซ้ำ ไม่งั้นค้างที่ opacity 0 */
watch(content, () => nextTick(observeReveals))
</script>

<style scoped>
/* Hero entrance */
.hero-content {
  animation: slideUpFade 0.9s cubic-bezier(0.16, 1, 0.3, 1) both;
}

@keyframes slideUpFade {
  from { opacity: 0; transform: translateY(24px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* Problem card — โทนสีของแต่ละปัญหามาจาก --tone */
.problem-icon {
  color: var(--tone);
  background-color: color-mix(in srgb, var(--tone) 12%, white);
}

.problem-card:hover {
  border-color: color-mix(in srgb, var(--tone) 45%, white);
}

.problem-card:hover .problem-icon {
  color: #fff;
  background-color: var(--tone);
}

/* Reveal on scroll */
.reveal-section {
  opacity: 0;
  transform: translateY(24px);
  transition-property: opacity, transform, border-color;
  transition-duration: 0.7s, 0.7s, 0.3s;
  transition-delay: var(--reveal-delay, 0s), var(--reveal-delay, 0s), 0s;
  transition-timing-function: cubic-bezier(0.16, 1, 0.3, 1);
}

.reveal-section.is-visible {
  opacity: 1;
  transform: translateY(0);
}

/* Respect reduced motion */
@media (prefers-reduced-motion: reduce) {
  .hero-content { animation: none; }
  .reveal-section { opacity: 1; transform: none; transition: none; }
}
</style>
