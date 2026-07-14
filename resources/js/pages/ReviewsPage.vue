<template>
  <div class="reviews-page font-anuphan bg-[var(--color-sand)] min-h-screen">
    <!-- Hero -->
    <section class="relative min-h-[320px] flex items-center overflow-hidden -mt-16 bg-[var(--color-primary)]">
      <div class="absolute inset-0">
        <img src="/images/landscape.webp" alt="Customer Reviews" class="w-full h-full object-cover" />
        <div class="absolute inset-0 bg-gradient-to-b from-black/40 via-black/30 to-[var(--color-primary)]/90"></div>
      </div>
      <div class="relative z-10 w-full px-6 md:px-8 py-24 md:py-32 text-center flex flex-col items-center">
        <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/10 backdrop-blur-sm border border-white/20 text-white/90 text-sm font-bold mb-5">
          <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 1">verified</span>
          รีวิวจริงจากผู้ร่วมเดินทาง
        </div>
        <h1 class="text-4xl md:text-6xl font-black text-white leading-tight mb-4 tracking-tight drop-shadow-md">
          รีวิวจากลูกค้า
        </h1>
        <p class="text-lg md:text-xl text-white/80 font-medium max-w-2xl mx-auto tracking-wide">
          ความประทับใจจากเหล่านักเดินทางที่ร่วมสร้างประสบการณ์กับลุยเลเขา
        </p>
      </div>
    </section>

    <section class="py-12 md:py-20">
      <div class="max-w-5xl mx-auto px-4 md:px-8 space-y-8">
        <!-- Rating overview panel -->
        <div v-if="stats && stats.total > 0" class="bg-white rounded-[2rem] border border-[var(--color-sand-dark)] overflow-hidden">
          <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,300px)_1fr]">
            <!-- Average score -->
            <div class="p-8 md:p-10 flex flex-col items-center justify-center text-center bg-[var(--color-primary)] text-white">
              <div class="text-6xl md:text-7xl font-black leading-none tracking-tight">{{ stats.average.toFixed(1) }}</div>
              <div class="flex items-center gap-0.5 mt-3 text-[var(--color-gold)]">
                <span
                  v-for="i in 5"
                  :key="i"
                  class="material-symbols-rounded text-[22px]"
                  :class="i <= Math.round(stats.average) ? 'text-[var(--color-gold)]' : 'text-white/25'"
                  :style="i <= Math.round(stats.average) ? 'font-variation-settings:\'FILL\' 1' : ''"
                >star</span>
              </div>
              <div class="mt-3 text-sm font-bold text-white/80">
                จาก {{ stats.total.toLocaleString() }} รีวิว
              </div>
              <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1 mt-4 text-xs font-bold text-white/60">
                <span class="inline-flex items-center gap-1">
                  <span class="material-symbols-rounded text-[15px]">photo_library</span>
                  {{ stats.with_media.toLocaleString() }} มีรูป/วิดีโอ
                </span>
                <span class="inline-flex items-center gap-1">
                  <span class="material-symbols-rounded text-[15px]">forum</span>
                  {{ stats.with_reply.toLocaleString() }} ตอบกลับแล้ว
                </span>
              </div>
            </div>

            <!-- Distribution + categories -->
            <div class="p-6 md:p-8 grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
              <!-- Star distribution -->
              <div>
                <h3 class="text-[13px] font-black uppercase tracking-wider text-[var(--color-text-muted)] mb-3">การให้ดาว</h3>
                <div class="space-y-2">
                  <button
                    v-for="level in [5, 4, 3, 2, 1]"
                    :key="level"
                    type="button"
                    @click="toggleRating(level)"
                    class="w-full flex items-center gap-3 group"
                    :class="ratingFilter === level ? 'opacity-100' : 'opacity-90 hover:opacity-100'"
                  >
                    <span class="flex items-center gap-0.5 w-9 text-sm font-bold text-[var(--color-text-dark)] shrink-0">
                      {{ level }}
                      <span class="material-symbols-rounded text-[14px] text-[var(--color-gold)]" style="font-variation-settings:'FILL' 1">star</span>
                    </span>
                    <span class="flex-1 h-2.5 rounded-full bg-[var(--color-sand-dark)] overflow-hidden">
                      <span
                        class="block h-full rounded-full transition-all duration-500"
                        :class="ratingFilter === level ? 'bg-[var(--color-gold)]' : 'bg-[var(--color-accent)]'"
                        :style="{ width: distPercent(level) + '%' }"
                      ></span>
                    </span>
                    <span class="w-10 text-right text-xs font-bold text-[var(--color-text-muted)] tabular-nums shrink-0">
                      {{ (stats.distribution[level] || 0).toLocaleString() }}
                    </span>
                  </button>
                </div>
              </div>

              <!-- Category averages -->
              <div v-if="activeCategories.length">
                <h3 class="text-[13px] font-black uppercase tracking-wider text-[var(--color-text-muted)] mb-3">คะแนนรายหมวด</h3>
                <div class="space-y-3">
                  <div v-for="cat in activeCategories" :key="cat.key" class="flex items-center gap-3">
                    <span class="w-8 h-8 rounded-xl bg-[var(--color-sand)] flex items-center justify-center text-[var(--color-accent)] shrink-0">
                      <span class="material-symbols-rounded text-[18px]">{{ cat.icon }}</span>
                    </span>
                    <span class="flex-1 text-sm font-bold text-[var(--color-text-dark)]">{{ cat.label }}</span>
                    <span class="flex items-center gap-1.5">
                      <span class="w-16 h-1.5 rounded-full bg-[var(--color-sand-dark)] overflow-hidden">
                        <span class="block h-full rounded-full bg-[var(--color-gold)]" :style="{ width: (cat.average / 5 * 100) + '%' }"></span>
                      </span>
                      <span class="w-7 text-right text-sm font-black text-[var(--color-text-dark)] tabular-nums">{{ cat.average.toFixed(1) }}</span>
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Filter chips -->
        <div v-if="stats && stats.total > 0" class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            @click="setRating(null)"
            class="px-4 py-2 rounded-full text-sm font-bold transition-all"
            :class="ratingFilter === null ? 'bg-[var(--color-primary)] text-white' : 'bg-white border border-[var(--color-sand-dark)] text-[var(--color-text-mid)] hover:border-[var(--color-accent)]'"
          >
            ทั้งหมด
          </button>
          <button
            v-for="level in [5, 4, 3, 2, 1]"
            :key="level"
            type="button"
            @click="setRating(level)"
            :disabled="!(stats.distribution[level] > 0)"
            class="inline-flex items-center gap-1 px-4 py-2 rounded-full text-sm font-bold transition-all disabled:opacity-40 disabled:cursor-not-allowed"
            :class="ratingFilter === level ? 'bg-[var(--color-primary)] text-white' : 'bg-white border border-[var(--color-sand-dark)] text-[var(--color-text-mid)] hover:border-[var(--color-accent)]'"
          >
            {{ level }}
            <span class="material-symbols-rounded text-[16px] text-[var(--color-gold)]" style="font-variation-settings:'FILL' 1">star</span>
          </button>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="text-center py-24 bg-white rounded-[2rem] border border-[var(--color-sand-dark)]">
          <div class="w-14 h-14 border-4 border-[var(--color-sand-dark)] border-t-[var(--color-accent)] rounded-full animate-spin mx-auto"></div>
          <p class="mt-6 text-[var(--color-text-dark)] font-extrabold">กำลังโหลดรีวิวจากลูกค้าจริง...</p>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="text-center py-20 bg-red-50 rounded-[2rem] border border-red-100">
          <span class="material-symbols-rounded text-5xl text-red-300 mb-4">wifi_off</span>
          <h3 class="text-xl font-extrabold text-[var(--color-text-dark)] mb-2">โหลดรีวิวไม่สำเร็จ</h3>
          <p class="text-[var(--color-text-muted)] font-medium mb-6">{{ error }}</p>
          <button
            @click="fetchReviews(currentPage)"
            class="inline-flex items-center gap-2 px-6 py-3 bg-[var(--color-primary)] text-white rounded-full font-bold hover:bg-[var(--color-primary-mid)] transition-all"
          >
            <span class="material-symbols-rounded text-[20px]">refresh</span>
            ลองใหม่
          </button>
        </div>

        <!-- Empty -->
        <div v-else-if="reviews.length === 0" class="text-center py-20 bg-white rounded-[2rem] border border-dashed border-[var(--color-sand-dark)]">
          <span class="material-symbols-rounded text-5xl text-gray-300 mb-4">rate_review</span>
          <h3 class="text-xl font-extrabold text-[var(--color-text-dark)] mb-2">
            {{ ratingFilter ? 'ยังไม่มีรีวิวระดับนี้' : 'ยังไม่มีรีวิวจากลูกค้า' }}
          </h3>
          <p class="text-[var(--color-text-muted)] font-medium">
            {{ ratingFilter ? 'ลองเลือกดูระดับดาวอื่น' : 'เมื่อมีรีวิวที่ผ่านการอนุมัติแล้ว จะแสดงที่หน้านี้ทันที' }}
          </p>
        </div>

        <!-- Reviews grid -->
        <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-5">
          <article
            v-for="review in reviews"
            :key="review.id"
            class="flex flex-col p-6 bg-white border border-[var(--color-sand-dark)] rounded-[1.75rem] transition-colors hover:border-[var(--color-accent)]/40"
          >
            <!-- Header: avatar + name + trip -->
            <div class="flex items-start gap-3">
              <div class="w-11 h-11 rounded-full bg-[var(--color-primary)] flex items-center justify-center text-white font-bold overflow-hidden shrink-0">
                <img v-if="avatarUrl(review)" :src="avatarUrl(review)" :alt="review.user_name" class="w-full h-full object-cover" />
                <span v-else>{{ avatarInitial(review) }}</span>
              </div>
              <div class="min-w-0 flex-1">
                <div class="font-bold text-[var(--color-text-dark)] truncate">{{ review.user_name }}</div>
                <router-link
                  v-if="review.trip_id"
                  :to="`/trips/${review.trip_id}`"
                  class="text-sm text-[var(--color-text-muted)] font-medium truncate block hover:text-[var(--color-accent)] transition-colors"
                >{{ review.trip_title }}</router-link>
                <span v-else class="text-sm text-[var(--color-text-muted)] font-medium truncate block">{{ review.trip_title }}</span>
              </div>
              <span class="text-xs text-[var(--color-text-muted)] font-bold shrink-0 mt-0.5">{{ formatDate(review.created_at) }}</span>
            </div>

            <!-- Overall stars -->
            <div class="flex items-center gap-0.5 mt-4">
              <span
                v-for="i in 5"
                :key="i"
                class="material-symbols-rounded text-[20px]"
                :class="i <= review.rating ? 'text-[var(--color-gold)]' : 'text-[var(--color-sand-dark)]'"
                :style="i <= review.rating ? 'font-variation-settings:\'FILL\' 1' : ''"
              >star</span>
            </div>

            <!-- Comment -->
            <p class="mt-3 text-[var(--color-text-mid)] font-medium leading-relaxed whitespace-pre-line flex-1">
              {{ review.comment || 'ไม่มีความคิดเห็นเพิ่มเติม' }}
            </p>

            <!-- Sub-ratings -->
            <div v-if="reviewCategories(review).length" class="flex flex-wrap gap-1.5 mt-4">
              <span
                v-for="cat in reviewCategories(review)"
                :key="cat.key"
                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-[var(--color-sand)] text-xs font-bold text-[var(--color-text-mid)]"
              >
                <span class="material-symbols-rounded text-[14px] text-[var(--color-accent)]">{{ cat.icon }}</span>
                {{ cat.label }}
                <span class="text-[var(--color-gold-dark)]">{{ cat.value }}.0</span>
              </span>
            </div>

            <!-- Media -->
            <div v-if="review.media.length" class="flex flex-wrap gap-2 mt-4">
              <button
                v-for="(item, index) in review.media"
                :key="index"
                type="button"
                @click="openLightbox(review.media, index)"
                class="media-thumb group relative w-[72px] h-[72px] rounded-xl overflow-hidden border border-[var(--color-sand-dark)]"
                :aria-label="item.type === 'video' ? 'เล่นวิดีโอรีวิว' : 'ดูรูปรีวิว'"
              >
                <img
                  v-if="item.type === 'image'"
                  :src="item.url"
                  :alt="`Review media ${index + 1}`"
                  loading="lazy"
                  class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
                />
                <template v-else>
                  <video :src="item.url" class="w-full h-full object-cover" muted playsinline preload="metadata"></video>
                  <span class="absolute inset-0 flex items-center justify-center bg-black/30 transition-colors group-hover:bg-black/45">
                    <span class="material-symbols-rounded text-white text-3xl drop-shadow-md" style="font-variation-settings:'FILL' 1">play_circle</span>
                  </span>
                </template>
              </button>
            </div>

            <!-- Admin reply -->
            <div v-if="review.admin_reply" class="mt-4 bg-[var(--color-sand)] rounded-2xl p-4 border-l-4 border-[var(--color-accent)]">
              <p class="text-xs font-black text-[var(--color-accent)] mb-1 flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[15px]" style="font-variation-settings:'FILL' 1">verified</span>
                {{ review.admin_replied_by || 'ทีมงานลุยเลเขา' }} ตอบกลับ
              </p>
              <p class="text-sm font-medium text-[var(--color-text-dark)] leading-relaxed whitespace-pre-line">
                {{ review.admin_reply }}
              </p>
            </div>
          </article>
        </div>

        <!-- Pagination -->
        <div v-if="!loading && !error && lastPage > 1" class="flex justify-center items-center gap-2 pt-2">
          <button
            @click="fetchReviews(currentPage - 1)"
            :disabled="currentPage <= 1 || loading"
            class="w-11 h-11 rounded-full flex items-center justify-center bg-white border border-[var(--color-sand-dark)] text-[var(--color-text-dark)] disabled:opacity-40 disabled:cursor-not-allowed hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition-colors"
          >
            <span class="material-symbols-rounded">chevron_left</span>
          </button>
          <button
            v-for="item in paginationItems"
            :key="item.key"
            @click="item.page && fetchReviews(item.page)"
            :disabled="!item.page || item.page === currentPage || loading"
            class="min-w-11 h-11 px-4 rounded-full flex items-center justify-center font-extrabold transition-all"
            :class="item.page === currentPage
              ? 'bg-[var(--color-accent)] text-white'
              : item.page
                ? 'bg-white border border-[var(--color-sand-dark)] text-[var(--color-text-dark)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)]'
                : 'text-gray-400 cursor-default'"
          >
            {{ item.page || '...' }}
          </button>
          <button
            @click="fetchReviews(currentPage + 1)"
            :disabled="currentPage >= lastPage || loading"
            class="w-11 h-11 rounded-full flex items-center justify-center bg-white border border-[var(--color-sand-dark)] text-[var(--color-text-dark)] disabled:opacity-40 disabled:cursor-not-allowed hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition-colors"
          >
            <span class="material-symbols-rounded">chevron_right</span>
          </button>
        </div>

        <!-- CTA -->
        <div class="bg-[var(--color-primary)] rounded-[2rem] p-8 md:p-12 text-center">
          <h2 class="text-2xl md:text-3xl font-black text-white mb-3">พร้อมสร้างเรื่องราวของคุณแล้วหรือยัง?</h2>
          <p class="text-white/70 font-medium mb-6 max-w-xl mx-auto">ออกเดินทางไปกับลุยเลเขา แล้วมาแบ่งปันความประทับใจของคุณให้นักเดินทางคนต่อไป</p>
          <router-link
            to="/trips"
            class="inline-flex items-center gap-2 px-8 py-3.5 bg-[var(--color-gold)] text-[var(--color-primary)] rounded-full font-black hover:bg-[var(--color-gold-dark)] hover:text-white transition-all"
          >
            จองทริปและสร้างรีวิวของคุณ
            <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
          </router-link>
        </div>
      </div>
    </section>

    <Teleport to="body">
      <Transition name="lightbox">
        <div v-if="lightboxItems" class="lightbox" @click.self="closeLightbox">
          <button class="lightbox-btn lightbox-close" @click="closeLightbox" aria-label="ปิด">
            <span class="material-symbols-rounded">close</span>
          </button>
          <button v-if="lightboxItems.length > 1" class="lightbox-btn lightbox-prev" @click.stop="prevMedia" aria-label="ก่อนหน้า">
            <span class="material-symbols-rounded">chevron_left</span>
          </button>

          <div class="lightbox-figure">
            <img
              v-if="currentMedia.type === 'image'"
              :key="`img-${lightboxIndex}`"
              :src="currentMedia.url"
              alt="Review media"
              class="lightbox-img"
            />
            <video
              v-else
              :key="`vid-${lightboxIndex}`"
              :src="currentMedia.url"
              class="lightbox-video"
              controls
              autoplay
              playsinline
            ></video>
          </div>

          <button v-if="lightboxItems.length > 1" class="lightbox-btn lightbox-next" @click.stop="nextMedia" aria-label="ถัดไป">
            <span class="material-symbols-rounded">chevron_right</span>
          </button>

          <div v-if="lightboxItems.length > 1" class="lightbox-counter">{{ lightboxIndex + 1 }} / {{ lightboxItems.length }}</div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import api from '../lib/axios';

const reviews = ref([]);
const meta = ref(null);
const stats = ref(null);
const loading = ref(true);
const error = ref('');
const ratingFilter = ref(null);

const lightboxItems = ref(null);
const lightboxIndex = ref(0);
const currentMedia = computed(() => lightboxItems.value?.[lightboxIndex.value] || {});

const currentPage = computed(() => Number(meta.value?.current_page || 1));
const lastPage = computed(() => Number(meta.value?.last_page || 1));

const CATEGORY_META = [
  { key: 'guide', label: 'ไกด์นำเที่ยว', icon: 'hiking' },
  { key: 'vehicle', label: 'ยานพาหนะ', icon: 'directions_bus' },
  { key: 'food', label: 'อาหาร', icon: 'restaurant' },
  { key: 'value', label: 'ความคุ้มค่า', icon: 'savings' },
];

const activeCategories = computed(() => {
  if (!stats.value?.categories) return [];
  return CATEGORY_META
    .map((c) => ({ ...c, ...(stats.value.categories[c.key] || {}) }))
    .filter((c) => c.count > 0 && c.average != null);
});

function reviewCategories(review) {
  return CATEGORY_META
    .map((c) => ({ ...c, value: review[`rating_${c.key}`] }))
    .filter((c) => c.value != null && c.value > 0);
}

function distPercent(level) {
  const total = stats.value?.total || 0;
  if (!total) return 0;
  return Math.round(((stats.value.distribution[level] || 0) / total) * 100);
}

const paginationItems = computed(() => {
  const current = currentPage.value;
  const last = lastPage.value;
  const pages = [];

  if (last <= 7) {
    for (let page = 1; page <= last; page += 1) pages.push(page);
    return pages.map((page) => ({ page, key: `page-${page}` }));
  }

  pages.push(1);
  if (current > 3) pages.push(null);
  for (let page = Math.max(2, current - 1); page <= Math.min(last - 1, current + 1); page += 1) {
    pages.push(page);
  }
  if (current < last - 2) pages.push(null);
  pages.push(last);
  return pages.map((page, index) => ({ page, key: page ? `page-${page}` : `gap-${index}` }));
});

function setRating(level) {
  if (ratingFilter.value === level) return;
  ratingFilter.value = level;
  fetchReviews(1);
}

function toggleRating(level) {
  setRating(ratingFilter.value === level ? null : level);
}

async function fetchStats() {
  try {
    const res = await api.get('/reviews/stats');
    stats.value = res.data?.data || null;
  } catch {
    stats.value = null;
  }
}

async function fetchReviews(page = 1) {
  loading.value = true;
  error.value = '';

  try {
    const params = { page, per_page: 8 };
    if (ratingFilter.value) params.rating = ratingFilter.value;

    const res = await api.get('/reviews', { params });

    reviews.value = (res.data?.data || []).map((review) => ({
      ...review,
      media: buildMedia(review),
    }));
    meta.value = res.data?.meta || null;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  } catch (err) {
    error.value = err.response?.data?.message || err.message || 'ไม่สามารถโหลดข้อมูลรีวิวได้';
  } finally {
    loading.value = false;
  }
}

function buildMedia(review) {
  const images = (review.images || []).map((url) => ({ type: 'image', url: mediaUrl(url) }));
  const videos = (review.videos || []).map((url) => ({ type: 'video', url: mediaUrl(url) }));
  return [...images, ...videos].filter((item) => item.url);
}

function openLightbox(items, index) {
  lightboxItems.value = items;
  lightboxIndex.value = index;
  document.body.style.overflow = 'hidden';
}

function closeLightbox() {
  lightboxItems.value = null;
  document.body.style.overflow = '';
}

function prevMedia() {
  const total = lightboxItems.value.length;
  lightboxIndex.value = (lightboxIndex.value - 1 + total) % total;
}

function nextMedia() {
  const total = lightboxItems.value.length;
  lightboxIndex.value = (lightboxIndex.value + 1) % total;
}

function onKeydown(e) {
  if (!lightboxItems.value) return;
  if (e.key === 'Escape') closeLightbox();
  else if (e.key === 'ArrowLeft') prevMedia();
  else if (e.key === 'ArrowRight') nextMedia();
}

function mediaUrl(value) {
  if (!value) return '';
  const raw = String(value);
  if (raw.startsWith('http://') || raw.startsWith('https://')) return raw;
  if (raw.startsWith('/')) return `${window.location.origin}${raw}`;
  return `${window.location.origin}/${raw}`;
}

function avatarUrl(review) {
  return mediaUrl(review.user_avatar || review.user?.avatar_url || review.user?.avatar || '');
}

function avatarInitial(review) {
  return (review.user_name || '?').trim().charAt(0).toUpperCase();
}

function formatDate(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return new Intl.DateTimeFormat('th-TH', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(date);
}

onMounted(() => {
  window.scrollTo(0, 0);
  fetchStats();
  fetchReviews();
  window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown);
  document.body.style.overflow = '';
});
</script>

<style scoped>
.reviews-page {
  animation: fadeIn 0.8s ease-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.media-thumb {
  cursor: pointer;
  transition: transform 0.2s ease;
}
.media-thumb:hover {
  transform: translateY(-2px);
}
.media-thumb:focus-visible {
  outline: 2px solid var(--color-accent);
  outline-offset: 2px;
}

/* Lightbox */
.lightbox {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: rgba(8, 12, 10, 0.92);
  backdrop-filter: blur(6px);
}
.lightbox-figure {
  max-width: min(1100px, 92vw);
  max-height: 88vh;
  margin: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
.lightbox-img,
.lightbox-video {
  max-width: 100%;
  max-height: 82vh;
  object-fit: contain;
  border-radius: 0.75rem;
}
.lightbox-video {
  background: #000;
  width: min(900px, 92vw);
}

.lightbox-btn {
  position: absolute;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  border: none;
  border-radius: 999px;
  color: #fff;
  background: rgba(255, 255, 255, 0.12);
  cursor: pointer;
  transition: background 0.2s ease;
}
.lightbox-btn:hover { background: rgba(255, 255, 255, 0.25); }
.lightbox-btn .material-symbols-rounded { font-size: 28px; }
.lightbox-close { top: 20px; right: 20px; }
.lightbox-prev { left: 16px; top: 50%; transform: translateY(-50%); }
.lightbox-next { right: 16px; top: 50%; transform: translateY(-50%); }

.lightbox-counter {
  position: absolute;
  bottom: 20px;
  left: 50%;
  transform: translateX(-50%);
  color: rgba(255, 255, 255, 0.75);
  font-weight: 700;
  font-size: 13px;
  letter-spacing: 0.05em;
}

.lightbox-enter-active, .lightbox-leave-active { transition: opacity 0.25s ease; }
.lightbox-enter-from, .lightbox-leave-to { opacity: 0; }
</style>
