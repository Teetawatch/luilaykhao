<template>
  <section v-if="articles.length" class="bg-white py-16 md:py-20">
    <div class="max-w-7xl mx-auto px-6 md:px-8">
      <div class="flex items-end justify-between gap-6 mb-8">
        <div>
          <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] tracking-tight">
            อ่านก่อนออกเดินทาง
          </h2>
          <p class="text-[var(--color-text-muted)] text-sm md:text-base mt-2 max-w-xl leading-relaxed">
            คู่มือเตรียมตัว รีวิวเส้นทาง และเรื่องที่ควรรู้ก่อนไป เขียนจากทริปที่เราพาไปเอง
          </p>
        </div>
        <a
          href="/blog"
          class="hidden sm:inline-flex items-center gap-1 text-sm font-bold text-[var(--color-primary)] hover:text-[var(--color-accent)] transition-colors shrink-0"
        >
          บทความทั้งหมด
          <span class="material-symbols-rounded text-[18px]">arrow_forward</span>
        </a>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <a
          v-for="article in articles"
          :key="article.id"
          :href="`/blog/${article.slug}`"
          class="group flex flex-col"
        >
          <div class="aspect-[16/10] rounded-2xl overflow-hidden bg-[var(--color-sand)] mb-4">
            <img
              v-if="article.cover_image_url"
              :src="article.cover_image_url"
              :alt="article.title"
              loading="lazy"
              class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-[1.03]"
            />
          </div>
          <div class="flex items-center gap-2 text-[12px] font-bold text-[var(--color-text-muted)] mb-2">
            <span v-if="article.category">{{ article.category.name }}</span>
            <span v-if="article.category && article.reading_minutes">·</span>
            <span v-if="article.reading_minutes">อ่าน {{ article.reading_minutes }} นาที</span>
          </div>
          <h3 class="text-lg font-extrabold text-[var(--color-text-dark)] leading-snug mb-2 group-hover:text-[var(--color-accent)] transition-colors">
            {{ article.title }}
          </h3>
          <p v-if="article.excerpt" class="text-sm text-[var(--color-text-muted)] leading-relaxed line-clamp-2">
            {{ article.excerpt }}
          </p>
        </a>
      </div>

      <a
        href="/blog"
        class="sm:hidden mt-8 flex items-center justify-center gap-1.5 w-full py-3 rounded-full border border-black/10 text-sm font-bold text-[var(--color-primary)]"
      >
        บทความทั้งหมด
        <span class="material-symbols-rounded text-[18px]">arrow_forward</span>
      </a>
    </div>
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../lib/axios';

/** โชว์ 3 บทความล่าสุด — พอให้เห็นว่ามีเนื้อหา ไม่แย่งพื้นที่ทริป */
const MAX_ARTICLES = 3;

const articles = ref([]);

onMounted(async () => {
  try {
    const res = await api.get('/articles');
    articles.value = (res.data?.data || []).slice(0, MAX_ARTICLES);
  } catch {
    articles.value = [];
  }
});
</script>

<style scoped>
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
