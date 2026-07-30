<template>
  <div class="faq-page font-anuphan bg-[var(--color-sand)] min-h-screen">
    <!-- HERO SECTION -->
    <section class="relative min-h-[300px] flex items-center overflow-hidden -mt-16 bg-[var(--color-primary)]">
      <div class="absolute inset-0">
        <img
          src="/images/landscape.webp"
          alt="FAQ"
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
          
          <div v-for="(group, gIndex) in content.groups" :key="gIndex" class="space-y-6">
            <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
              <span class="w-2 h-8 rounded-full" :class="TONE_CLASSES[group.tone] || TONE_CLASSES.primary"></span>
              {{ group.title }}
            </h2>
            
            <div class="space-y-4">
              <div v-for="(item, iIndex) in group.questions" :key="iIndex" class="p-6 border border-[var(--color-sand-dark)] rounded-3xl hover:border-primary/30 transition-all group">
                <h3 class="text-lg font-bold text-[var(--color-text-dark)] mb-2 flex items-start gap-3">
                  <span class="text-primary font-black">Q:</span>
                  {{ item.q }}
                </h3>
                <div class="flex items-start gap-3">
                   <span class="text-[var(--color-accent)] font-black">A:</span>
                   <p class="text-[var(--color-text-mid)] font-medium leading-relaxed">
                    {{ item.a }}
                  </p>
                </div>
              </div>
            </div>
          </div>

          <div class="pt-8 border-t border-[var(--color-sand-dark)] text-center">
            <p class="text-[var(--color-text-muted)] font-medium mb-6">{{ content.footer_text }}</p>
             <router-link to="/contact" class="inline-flex items-center gap-2 px-8 py-3.5 border-2 border-[var(--color-primary)] text-[var(--color-primary)] rounded-full font-bold hover:bg-[var(--color-primary)] hover:text-white transition-all">
              <span class="material-symbols-rounded text-[20px]">chat</span>
              {{ content.footer_cta }}
            </router-link>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { onMounted, computed } from 'vue'
import { useHead } from '@unhead/vue';
import { usePageContent } from '../lib/pageContent';

/** แอดมินเลือกได้แค่ "โทน" ส่วนคลาสจริงคุมไว้ที่นี่ */
const TONE_CLASSES = {
  primary: 'bg-[var(--color-primary)]',
  accent: 'bg-[var(--color-accent)]',
  gold: 'bg-[var(--color-gold)]',
  teal: 'bg-teal-500',
};

const { content } = usePageContent('faq', {
  hero_title: '',
  hero_subtitle: '',
  groups: [],
  footer_text: '',
  footer_cta: '',
});

// Generate FAQ JSON-LD for Google rich results
const faqJsonLd = computed(() => {
  const allQuestions = (content.value.groups || []).flatMap(group =>
    (group.questions || []).map(item => ({
      '@type': 'Question',
      name: item.q,
      acceptedAnswer: {
        '@type': 'Answer',
        text: item.a
      }
    }))
  );
  return JSON.stringify({
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: allQuestions
  });
});

useHead({
  script: [
    { type: 'application/ld+json', innerHTML: faqJsonLd }
  ]
});

onMounted(() => {
  window.scrollTo(0, 0)
})
</script>

<style scoped>
.faq-page {
  animation: fadeIn 0.8s ease-out;
}
@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
</style>
