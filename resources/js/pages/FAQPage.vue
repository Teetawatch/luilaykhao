<template>
  <div class="faq-page font-anuphan bg-[var(--color-sand)] min-h-screen">

    <PageHero
      image="/images/landscape.webp"
      :title="content.hero_title"
      :subtitle="content.hero_subtitle"
      :loading="loading"
    >
      <!-- Search -->
      <div class="mt-8 relative max-w-xl">
        <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-[22px] text-[var(--color-text-muted)] pointer-events-none">search</span>
        <input
          v-model="query"
          type="search"
          inputmode="search"
          enterkeyhint="search"
          aria-label="ค้นหาคำถาม"
          placeholder="ค้นหา เช่น มัดจำ, ยกเลิก, จุดขึ้นรถ"
          class="w-full rounded-2xl bg-white py-4 pl-12 pr-12 text-[15px] font-semibold text-[var(--color-text-dark)] placeholder:text-[var(--color-text-muted)]/70 placeholder:font-medium outline-none focus:ring-4 focus:ring-white/30"
        />
        <button
          v-if="query"
          type="button"
          class="absolute right-3 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full flex items-center justify-center text-[var(--color-text-muted)] hover:bg-[var(--color-sand)]"
          aria-label="ล้างคำค้นหา"
          @click="query = ''"
        >
          <span class="material-symbols-rounded text-[20px]">close</span>
        </button>
      </div>
    </PageHero>

    <section class="py-12 md:py-20">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-12">

          <!-- Categories -->
          <nav v-if="groups.length > 1" class="lg:col-span-3" aria-label="หมวดคำถาม">
            <div class="lg:sticky lg:top-28">
              <p class="hidden lg:block text-[13px] font-bold text-[var(--color-text-muted)] mb-3">หมวดคำถาม</p>
              <div class="-mx-4 px-4 lg:mx-0 lg:px-0 overflow-x-auto scrollbar-none">
                <div class="flex lg:flex-col gap-2 lg:gap-1 w-max lg:w-auto">
                  <button
                    v-for="group in groups"
                    :key="group.id"
                    type="button"
                    class="cat-btn"
                    :class="{ 'cat-btn--active': activeGroup === group.id, 'opacity-40': query && !group.matches.length }"
                    :disabled="!!query && !group.matches.length"
                    @click="jumpTo(group.id)"
                  >
                    <span class="w-2 h-2 rounded-full shrink-0" :class="TONE_CLASSES[group.tone] || TONE_CLASSES.primary"></span>
                    <span class="flex-1 text-left">{{ group.title }}</span>
                    <span class="text-[12px] font-bold tabular-nums opacity-60">{{ query ? group.matches.length : group.questions.length }}</span>
                  </button>
                </div>
              </div>
            </div>
          </nav>

          <!-- Questions -->
          <div :class="groups.length > 1 ? 'lg:col-span-9' : 'lg:col-span-12 max-w-3xl'" class="min-w-0">
            <p v-if="query" class="mb-5 text-sm font-semibold text-[var(--color-text-muted)]" aria-live="polite">
              พบ {{ matchCount }} คำถามที่เกี่ยวกับ “{{ query.trim() }}”
            </p>

            <div v-if="loading && !groups.length" class="space-y-3" aria-hidden="true">
              <div v-for="n in 6" :key="n" class="h-16 rounded-2xl bg-white border border-[var(--color-sand-dark)] animate-pulse"></div>
            </div>

            <div v-else-if="query && !matchCount" class="rounded-3xl border border-dashed border-[var(--color-sand-dark)] bg-white px-6 py-14 text-center">
              <span class="material-symbols-rounded text-5xl text-[var(--color-text-muted)]/50">search_off</span>
              <p class="mt-3 text-lg font-extrabold text-[var(--color-text-dark)]">ไม่พบคำถามที่ตรงกับคำค้นหา</p>
              <p class="mt-1 text-sm text-[var(--color-text-muted)]">ลองใช้คำอื่น หรือถามทีมงานโดยตรงได้เลยครับ</p>
              <button type="button" class="mt-5 rounded-xl border border-[var(--color-sand-dark)] px-5 py-2.5 text-sm font-bold text-[var(--color-text-dark)] hover:border-[var(--color-accent)]" @click="query = ''">ล้างคำค้นหา</button>
            </div>

            <div v-else class="space-y-10">
              <section
                v-for="group in visibleGroups"
                :id="group.id"
                :key="group.id"
                :ref="(el) => setGroupEl(group.id, el)"
                class="scroll-mt-28"
              >
                <h2 class="flex items-center gap-2.5 text-xl md:text-2xl font-extrabold text-[var(--color-text-dark)]">
                  <span class="w-1.5 h-6 rounded-full" :class="TONE_CLASSES[group.tone] || TONE_CLASSES.primary"></span>
                  {{ group.title }}
                </h2>

                <div class="mt-4 rounded-3xl border border-[var(--color-sand-dark)] bg-white divide-y divide-[var(--color-sand-dark)] overflow-hidden">
                  <div v-for="item in (query ? group.matches : group.questions)" :key="item.id" :id="item.id" class="scroll-mt-28">
                    <h3>
                      <button
                        type="button"
                        class="faq-q"
                        :aria-expanded="isOpen(item.id)"
                        :aria-controls="`${item.id}-a`"
                        @click="toggle(item.id)"
                      >
                        <span class="flex-1 text-left">{{ item.q }}</span>
                        <span class="faq-icon material-symbols-rounded" :class="{ 'rotate-45': isOpen(item.id) }" aria-hidden="true">add</span>
                      </button>
                    </h3>
                    <div v-show="isOpen(item.id)" :id="`${item.id}-a`" class="px-5 md:px-6 pb-5 -mt-1">
                      <p class="text-[15px] text-[var(--color-text-mid)] leading-relaxed whitespace-pre-line">{{ item.a }}</p>
                    </div>
                  </div>
                </div>
              </section>
            </div>

            <!-- Still need help -->
            <div class="mt-12 rounded-3xl bg-[var(--color-primary)] p-6 md:p-8 text-white flex flex-col md:flex-row md:items-center gap-5">
              <div class="flex-1">
                <p class="text-xl font-extrabold">{{ content.footer_text || 'ยังไม่เจอคำตอบที่ต้องการ?' }}</p>
                <p class="mt-1 text-[15px] text-white/70">ทีมงานตอบทาง LINE {{ supportLine() }} · {{ supportHours() }}</p>
              </div>
              <div class="flex flex-wrap gap-2 shrink-0">
                <router-link to="/contact" class="inline-flex items-center gap-2 rounded-xl bg-white px-5 py-3 text-sm font-bold text-[var(--color-primary)] hover:bg-[var(--color-sand)] transition-colors">
                  <span class="material-symbols-rounded text-[18px]">chat</span>
                  {{ content.footer_cta || 'ติดต่อเรา' }}
                </router-link>
                <a :href="supportLineUrl()" target="_blank" rel="noopener" class="inline-flex items-center rounded-xl border border-white/25 px-5 py-3 text-sm font-bold text-white hover:bg-white/10 transition-colors">
                  แชท LINE
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useHead } from '@unhead/vue'
import PageHero from '../components/PageHero.vue'
import { usePageContent } from '../lib/pageContent'
import { supportHours, supportLine, supportLineUrl } from '../lib/contact'

/** แอดมินเลือกได้แค่ "โทน" ส่วนคลาสจริงคุมไว้ที่นี่ */
const TONE_CLASSES = {
  primary: 'bg-[var(--color-primary)]',
  accent: 'bg-[var(--color-accent)]',
  gold: 'bg-[var(--color-gold)]',
  teal: 'bg-teal-500',
}

const { content, loading } = usePageContent('faq', {
  hero_title: '',
  hero_subtitle: '',
  groups: [],
  footer_text: '',
  footer_cta: '',
})

const query = ref('')

/** ตัดคำค้นเป็นคำ ๆ แล้วต้องเจอครบทุกคำ (ภาษาไทยไม่มีเว้นวรรค จึงจับแบบ substring) */
const terms = computed(() => query.value.trim().toLowerCase().split(/\s+/).filter(Boolean))

const groups = computed(() => (content.value.groups || [])
  .map((group, g) => {
    const questions = (group.questions || [])
      .filter((item) => item?.q)
      .map((item, i) => ({ ...item, id: `faq-${g + 1}-${i + 1}` }))
    const matches = terms.value.length
      ? questions.filter((item) => {
        const hay = `${item.q} ${item.a || ''}`.toLowerCase()
        return terms.value.every((t) => hay.includes(t))
      })
      : questions
    return { ...group, id: `faq-group-${g + 1}`, questions, matches }
  })
  .filter((group) => group.questions.length))

const visibleGroups = computed(() => (query.value ? groups.value.filter((g) => g.matches.length) : groups.value))
const matchCount = computed(() => groups.value.reduce((sum, g) => sum + g.matches.length, 0))

// ─── Accordion ───
const open = reactive(new Set())
const isOpen = (id) => !!query.value || open.has(id) // ตอนค้นหา เปิดทุกคำตอบที่เจอให้อ่านได้เลย
function toggle(id) {
  if (query.value) return
  open.has(id) ? open.delete(id) : open.add(id)
}

// ─── Category scroll-spy ───
const activeGroup = ref('')
const groupEls = new Map()
let spy = null

function setGroupEl(id, el) {
  if (el) groupEls.set(id, el)
  else groupEls.delete(id)
}

function jumpTo(id) {
  activeGroup.value = id
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  document.getElementById(id)?.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' })
}

function startSpy() {
  spy?.disconnect()
  if (!('IntersectionObserver' in window)) return
  spy = new IntersectionObserver(
    (entries) => {
      const visible = entries.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)
      if (visible[0]) activeGroup.value = visible[0].target.id
    },
    { rootMargin: '-120px 0px -60% 0px' },
  )
  groupEls.forEach((el) => spy.observe(el))
}

// ลิงก์ตรงไปคำถามเดียว (#faq-2-3) — เปิดคำตอบนั้นแล้วเลื่อนไปหา หลังเนื้อหาจาก CMS มาถึง
// ทำครั้งเดียว — groups คำนวณใหม่ทุกครั้งที่พิมพ์ค้นหา ถ้าไม่กันไว้จะเลื่อนกลับไปที่ hash ทุกตัวอักษร
let hashHandled = false
watch(groups, async (list) => {
  if (!list.length) return
  activeGroup.value ||= list[0].id
  await nextTick()
  startSpy()
  if (hashHandled) return
  hashHandled = true
  const hash = decodeURIComponent(window.location.hash.slice(1))
  if (hash.startsWith('faq-') && document.getElementById(hash)) {
    if (!hash.startsWith('faq-group-')) open.add(hash)
    await nextTick()
    document.getElementById(hash)?.scrollIntoView({ block: 'start' })
  }
}, { immediate: true })

onBeforeUnmount(() => spy?.disconnect())

// Generate FAQ JSON-LD for Google rich results
const faqJsonLd = computed(() => JSON.stringify({
  '@context': 'https://schema.org',
  '@type': 'FAQPage',
  mainEntity: groups.value.flatMap((group) => group.questions.map((item) => ({
    '@type': 'Question',
    name: item.q,
    acceptedAnswer: { '@type': 'Answer', text: item.a },
  }))),
}))

useHead({
  script: [
    { type: 'application/ld+json', innerHTML: faqJsonLd },
  ],
})
</script>

<style scoped>
.cat-btn {
  display: flex;
  align-items: center;
  gap: 0.625rem;
  padding: 0.625rem 0.875rem;
  border-radius: 0.875rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  font-size: 0.875rem;
  font-weight: 700;
  color: var(--color-text-mid);
  white-space: nowrap;
  transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
}
@media (min-width: 1024px) {
  .cat-btn { border-color: transparent; background-color: transparent; white-space: normal; }
  .cat-btn:hover:not(:disabled) { background-color: #fff; }
}
.cat-btn--active,
.cat-btn--active:hover:not(:disabled) {
  background-color: var(--color-primary) !important;
  border-color: var(--color-primary);
  color: #fff;
}

.faq-q {
  display: flex;
  align-items: flex-start;
  gap: 1rem;
  width: 100%;
  padding: 1.125rem 1.25rem;
  font-size: 1rem;
  font-weight: 700;
  line-height: 1.5;
  color: var(--color-text-dark);
  transition: background-color 0.2s ease;
}
@media (min-width: 768px) {
  .faq-q { padding: 1.25rem 1.5rem; }
}
.faq-q:hover { background-color: color-mix(in srgb, var(--color-sand) 60%, white); }
.faq-q:focus-visible { outline: 2px solid var(--color-accent); outline-offset: -2px; }
.faq-icon {
  flex-shrink: 0;
  margin-top: 0.0625rem;
  font-size: 22px;
  color: var(--color-accent);
  transition: transform 0.2s ease;
}

.scrollbar-none { scrollbar-width: none; }
.scrollbar-none::-webkit-scrollbar { display: none; }
</style>
