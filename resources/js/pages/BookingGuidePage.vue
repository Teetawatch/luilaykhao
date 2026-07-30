<template>
  <div class="booking-guide-page font-anuphan bg-[var(--color-sand)] min-h-screen">
    <!-- HERO SECTION -->
    <section class="relative min-h-[300px] flex items-center overflow-hidden -mt-16 bg-[var(--color-primary)]">
      <div class="absolute inset-0">
        <img
          src="/images/landscape.webp"
          alt="How to Book"
          class="w-full h-full object-cover"
        />
        <div class="absolute inset-0 bg-black/30"></div>
      </div>
      <div class="relative z-10 w-full px-6 md:px-8 py-24 md:py-32 text-center flex flex-col items-center">
        <div class="w-16 h-1.5 bg-[var(--color-accent)] mb-6 rounded-full"></div>
        <h1 class="text-4xl md:text-6xl font-black text-white leading-tight mb-4 tracking-tight">
          {{ content.hero_title }}
        </h1>
        <p class="text-lg md:text-xl text-white/80 font-bold max-w-2xl mx-auto tracking-wide">
          {{ content.hero_subtitle }}
        </p>
      </div>
    </section>

    <!-- CONTENT SECTION -->
    <section class="py-16 md:py-24">
      <div class="max-w-4xl mx-auto px-6 md:px-8">
        <div class="bg-white rounded-[2.5rem] p-8 md:p-12 border border-[var(--color-sand-dark)] space-y-12">
          
          <div class="space-y-10">
            <div
              v-for="(step, index) in content.steps"
              :key="index"
              class="flex flex-col md:flex-row gap-6 md:gap-10 items-start"
            >
              <div
                class="w-16 h-16 shrink-0 text-white rounded-2xl flex items-center justify-center text-3xl font-black"
                :class="STEP_COLORS[index % STEP_COLORS.length]"
              >{{ index + 1 }}</div>
              <div class="space-y-3">
                <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)]">{{ step.title }}</h2>
                <p class="text-lg text-[var(--color-text-mid)] leading-relaxed font-medium whitespace-pre-line">
                  {{ step.detail }}
                </p>
              </div>
            </div>
          </div>

          <div class="p-8 bg-blue-50 rounded-[2rem] border border-blue-100 flex flex-col md:flex-row items-center gap-6">
            <div class="w-16 h-16 bg-blue-500 rounded-full flex items-center justify-center text-white shrink-0">
              <span class="material-symbols-rounded text-[32px]">contact_support</span>
            </div>
            <div class="text-center md:text-left">
              <h3 class="text-xl font-bold text-blue-900 mb-1">{{ content.help_title }}</h3>
              <p class="text-blue-800/70 font-medium">{{ content.help_detail }}</p>
            </div>
          </div>

          <div class="pt-8 border-t border-[var(--color-sand-dark)] text-center">
             <router-link to="/trips" class="inline-flex items-center gap-2 px-10 py-4 bg-[var(--color-text-dark)] text-white rounded-full font-bold hover:bg-black transition-all">
              {{ content.cta_label }}
              <span class="material-symbols-rounded text-[20px]">shopping_cart</span>
            </router-link>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { usePageContent } from '../lib/pageContent';

/** สีประจำลำดับขั้นตอน วนซ้ำเมื่อแอดมินเพิ่มเกินสี่ขั้น */
const STEP_COLORS = [
  'bg-[var(--color-primary)]',
  'bg-[var(--color-accent)]',
  'bg-[var(--color-gold)]',
  'bg-teal-500',
];

const { content } = usePageContent('booking_guide', {
  hero_title: '',
  hero_subtitle: '',
  steps: [],
  help_title: '',
  help_detail: '',
  cta_label: '',
});

onMounted(() => {
  window.scrollTo(0, 0)
})
</script>

<style scoped>
.booking-guide-page {
  animation: fadeIn 0.8s ease-out;
}
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
</style>
