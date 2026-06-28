<template>
  <div class="reviews-page font-anuphan bg-[var(--color-sand)] min-h-screen">
    <section class="relative min-h-[300px] flex items-center overflow-hidden -mt-16 bg-[var(--color-primary)]">
      <div class="absolute inset-0">
        <img
          src="/images/landscape.webp"
          alt="Customer Reviews"
          class="w-full h-full object-cover"
        />
        <div class="absolute inset-0 bg-black/30"></div>
      </div>
      <div class="relative z-10 w-full px-6 md:px-8 py-24 md:py-32 text-center flex flex-col items-center">
        <div class="w-16 h-1.5 bg-[var(--color-accent)] mb-6 rounded-full shadow-lg"></div>
        <h1 class="text-4xl md:text-6xl font-black text-white leading-tight mb-4 tracking-tight drop-shadow-md">
          รีวิวจากลูกค้า
        </h1>
        <p class="text-lg md:text-xl text-white/80 font-bold max-w-2xl mx-auto tracking-wide">
          ความประทับใจจากเหล่านักเดินทางที่ร่วมสร้างประสบการณ์กับลุยเลเขา
        </p>
      </div>
    </section>

    <section class="py-16 md:py-24">
      <div class="max-w-4xl mx-auto px-6 md:px-8">
        <div class="bg-white rounded-[2.5rem] p-8 md:p-12 shadow-sm border border-[var(--color-sand-dark)] space-y-12">
          <div class="space-y-6">
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
              <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] flex items-center gap-3">
                <span class="w-2 h-8 bg-[var(--color-gold)] rounded-full"></span>
                เสียงสะท้อนจากหัวใจ
              </h2>
              <div v-if="!loading && totalReviews > 0" class="inline-flex items-center gap-2 self-start md:self-auto px-4 py-2 rounded-full bg-[var(--color-sand)] text-[var(--color-accent)] text-sm font-black">
                <span class="material-symbols-rounded text-[18px]">reviews</span>
                {{ totalReviews.toLocaleString() }} รีวิวจริง
              </div>
            </div>
            <p class="text-lg text-[var(--color-text-mid)] leading-relaxed">
              เราขอขอบคุณลูกค้าทุกท่านที่เลือกเดินทางไปกับเรา ทุกคำติชมคือพลังใจและการพัฒนางานบริการของเราให้ดียิ่งขึ้น
            </p>
          </div>

          <div v-if="loading" class="text-center py-24 bg-[var(--color-sand)] rounded-[2rem] border border-[var(--color-sand-dark)]">
            <div class="w-14 h-14 border-4 border-white border-t-[var(--color-accent)] rounded-full animate-spin mx-auto"></div>
            <p class="mt-6 text-[var(--color-text-dark)] font-extrabold">กำลังโหลดรีวิวจากลูกค้าจริง...</p>
          </div>

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

          <div v-else-if="reviews.length === 0" class="text-center py-20 bg-[var(--color-sand)] rounded-[2rem] border border-dashed border-[var(--color-sand-dark)]">
            <span class="material-symbols-rounded text-5xl text-gray-300 mb-4">rate_review</span>
            <h3 class="text-xl font-extrabold text-[var(--color-text-dark)] mb-2">ยังไม่มีรีวิวจากลูกค้า</h3>
            <p class="text-[var(--color-text-muted)] font-medium">เมื่อมีรีวิวที่ผ่านการอนุมัติแล้ว จะแสดงที่หน้านี้ทันที</p>
          </div>

          <template v-else>
            <article class="p-8 bg-[var(--color-sand)] rounded-[2rem] border border-[var(--color-sand-dark)] space-y-4">
              <div class="flex items-center gap-1 text-[var(--color-gold)]">
                <span
                  v-for="i in 5"
                  :key="i"
                  class="material-symbols-rounded"
                  :class="i <= featuredReview.rating ? 'text-[var(--color-gold)]' : 'text-gray-300'"
                  :style="i <= featuredReview.rating ? 'font-variation-settings:\'FILL\' 1' : ''"
                >
                  star
                </span>
              </div>
              <p class="text-xl font-bold text-[var(--color-text-dark)] italic leading-relaxed whitespace-pre-line">
                "{{ featuredReview.comment || 'ไม่มีความคิดเห็นเพิ่มเติม' }}"
              </p>
              <div v-if="featuredReview.media.length" class="flex flex-wrap gap-3">
                <button
                  v-for="(item, index) in featuredReview.media"
                  :key="index"
                  type="button"
                  @click="openLightbox(featuredReview.media, index)"
                  class="media-thumb group relative w-24 h-24 rounded-2xl overflow-hidden border border-white shadow-sm"
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
                      <span class="material-symbols-rounded text-white text-4xl drop-shadow-md" style="font-variation-settings:'FILL' 1">play_circle</span>
                    </span>
                  </template>
                </button>
              </div>
              <div class="flex items-center gap-4 pt-4 border-t border-[var(--color-sand-dark)]">
                <div class="w-12 h-12 rounded-full bg-[var(--color-primary)] flex items-center justify-center text-white font-bold text-lg shadow-md overflow-hidden">
                  <img
                    v-if="avatarUrl(featuredReview)"
                    :src="avatarUrl(featuredReview)"
                    :alt="featuredReview.user_name"
                    class="w-full h-full object-cover"
                  />
                  <span v-else>{{ avatarInitial(featuredReview) }}</span>
                </div>
                <div class="min-w-0">
                  <div class="font-bold text-[var(--color-text-dark)] truncate">{{ featuredReview.user_name }}</div>
                  <div class="text-sm text-[var(--color-text-muted)] font-medium truncate">{{ featuredReview.trip_title }}</div>
                  <div class="text-xs text-[var(--color-text-muted)] font-medium mt-0.5">{{ formatDate(featuredReview.created_at) }}</div>
                </div>
              </div>

              <div v-if="featuredReview.admin_reply" class="mt-4 bg-white/70 rounded-2xl p-5 border-l-4 border-[var(--color-accent)]">
                <p class="text-xs font-black text-[var(--color-accent)] uppercase tracking-widest mb-2 flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-[14px]" style="font-variation-settings:'FILL' 1">verified</span>
                  การตอบกลับจากผู้ดูแล
                </p>
                <p class="text-sm font-bold text-[var(--color-text-dark)] leading-relaxed whitespace-pre-line">
                  {{ featuredReview.admin_reply }}
                </p>
              </div>
            </article>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <article
                v-for="review in listReviews"
                :key="review.id"
                class="p-6 border border-[var(--color-sand-dark)] rounded-3xl space-y-4 hover:shadow-md transition-shadow"
              >
                <div class="flex items-center justify-between gap-3">
                  <div class="flex items-center gap-1">
                    <span
                      v-for="i in 5"
                      :key="i"
                      class="material-symbols-rounded text-[18px]"
                      :class="i <= review.rating ? 'text-[var(--color-gold)]' : 'text-gray-300'"
                      :style="i <= review.rating ? 'font-variation-settings:\'FILL\' 1' : ''"
                    >
                      star
                    </span>
                  </div>
                  <span class="text-xs text-[var(--color-text-muted)] font-bold bg-[var(--color-sand)] px-3 py-1 rounded-full">
                    {{ formatDate(review.created_at) }}
                  </span>
                </div>
                <p class="text-[var(--color-text-mid)] font-medium leading-relaxed whitespace-pre-line">
                  {{ review.comment || 'ไม่มีความคิดเห็นเพิ่มเติม' }}
                </p>
                <div v-if="review.media.length" class="flex flex-wrap gap-2">
                  <button
                    v-for="(item, index) in review.media"
                    :key="index"
                    type="button"
                    @click="openLightbox(review.media, index)"
                    class="media-thumb group relative w-16 h-16 rounded-xl overflow-hidden border border-[var(--color-sand-dark)]"
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
                        <span class="material-symbols-rounded text-white text-2xl drop-shadow-md" style="font-variation-settings:'FILL' 1">play_circle</span>
                      </span>
                    </template>
                  </button>
                </div>
                <div class="flex items-center gap-3 pt-2">
                  <div class="w-9 h-9 rounded-full bg-gray-200 flex items-center justify-center font-bold text-[var(--color-text-dark)] text-xs shadow-sm overflow-hidden">
                    <img
                      v-if="avatarUrl(review)"
                      :src="avatarUrl(review)"
                      :alt="review.user_name"
                      class="w-full h-full object-cover"
                    />
                    <span v-else>{{ avatarInitial(review) }}</span>
                  </div>
                  <div class="min-w-0">
                    <div class="text-[13px] font-bold text-[var(--color-text-dark)] truncate">{{ review.user_name }}</div>
                    <div class="text-xs text-[var(--color-text-muted)] font-medium truncate">{{ review.trip_title }}</div>
                  </div>
                </div>
                <div v-if="review.admin_reply" class="bg-[var(--color-sand)]/70 rounded-2xl p-4 border-l-4 border-[var(--color-accent)]">
                  <p class="text-xs font-black text-[var(--color-accent)] mb-1 flex items-center gap-1">
                    <span class="material-symbols-rounded text-[14px]">verified</span>
                    ผู้ดูแลตอบกลับ
                  </p>
                  <p class="text-sm font-bold text-[var(--color-text-dark)] leading-relaxed whitespace-pre-line">
                    {{ review.admin_reply }}
                  </p>
                </div>
              </article>
            </div>

            <div v-if="lastPage > 1" class="flex justify-center items-center gap-2 pt-4">
              <button
                @click="fetchReviews(currentPage - 1)"
                :disabled="currentPage <= 1 || loading"
                class="w-11 h-11 rounded-full flex items-center justify-center bg-[var(--color-sand)] text-[var(--color-text-dark)] disabled:text-gray-300 disabled:cursor-not-allowed hover:text-[var(--color-accent)] transition-colors"
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
                  ? 'bg-[var(--color-accent)] text-white shadow-lg shadow-[var(--color-accent)]/20'
                  : item.page
                    ? 'bg-[var(--color-sand)] text-[var(--color-text-dark)] hover:text-[var(--color-accent)]'
                    : 'text-gray-400 cursor-default'"
              >
                {{ item.page || '...' }}
              </button>
              <button
                @click="fetchReviews(currentPage + 1)"
                :disabled="currentPage >= lastPage || loading"
                class="w-11 h-11 rounded-full flex items-center justify-center bg-[var(--color-sand)] text-[var(--color-text-dark)] disabled:text-gray-300 disabled:cursor-not-allowed hover:text-[var(--color-accent)] transition-colors"
              >
                <span class="material-symbols-rounded">chevron_right</span>
              </button>
            </div>
          </template>

          <div class="pt-8 border-t border-[var(--color-sand-dark)] text-center">
            <router-link to="/trips" class="inline-flex items-center gap-2 px-8 py-3.5 bg-[var(--color-primary)] text-white rounded-full font-bold hover:bg-[var(--color-primary-mid)] transition-all shadow-lg shadow-primary/20">
              จองทริปและสร้างรีวิวของคุณ
              <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
            </router-link>
          </div>
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
const loading = ref(true);
const error = ref('');

const lightboxItems = ref(null);
const lightboxIndex = ref(0);
const currentMedia = computed(() => lightboxItems.value?.[lightboxIndex.value] || {});

const featuredReview = computed(() => reviews.value[0] || null);
const listReviews = computed(() => reviews.value.slice(1));
const currentPage = computed(() => Number(meta.value?.current_page || 1));
const lastPage = computed(() => Number(meta.value?.last_page || 1));
const totalReviews = computed(() => Number(meta.value?.total || reviews.value.length));

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

async function fetchReviews(page = 1) {
  loading.value = true;
  error.value = '';

  try {
    const res = await api.get('/reviews', {
      params: {
        page,
        per_page: 9,
      },
    });

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
  transition: box-shadow 0.2s ease, transform 0.2s ease;
}
.media-thumb:hover {
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
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
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
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
