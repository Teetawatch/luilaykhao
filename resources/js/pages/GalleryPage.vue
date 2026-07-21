<template>
  <div class="gallery-page font-anuphan bg-[var(--color-sand)] min-h-screen">
    <!-- Hero -->
    <section class="relative min-h-[300px] flex items-center overflow-hidden -mt-16 bg-[var(--color-primary)]">
      <div class="absolute inset-0">
        <img src="/images/landscape.webp" alt="รูปจากคนที่ไปมาแล้ว" class="w-full h-full object-cover" />
        <div class="absolute inset-0 bg-black/40"></div>
      </div>
      <div class="relative z-10 w-full px-6 md:px-8 py-24 md:py-32 text-center flex flex-col items-center">
        <div class="w-16 h-1.5 bg-[var(--color-accent)] mb-6 rounded-full"></div>
        <h1 class="text-4xl md:text-6xl font-black text-white leading-tight mb-4 tracking-tight">
          รูปจากคนที่ไปมาแล้ว
        </h1>
        <p class="text-lg md:text-xl text-white/80 font-bold max-w-2xl mx-auto tracking-wide">
          ทุกรูปในหน้านี้ผู้ร่วมทริปถ่ายเองและแนบมากับรีวิว ทีมงานไม่ได้คัดออก
        </p>
      </div>
    </section>

    <section class="py-14 md:py-20">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 md:px-8">

        <!-- Loading -->
        <div v-if="loading" class="text-center py-24">
          <div class="w-14 h-14 border-4 border-white border-t-[var(--color-accent)] rounded-full animate-spin mx-auto"></div>
          <p class="mt-6 text-[var(--color-text-dark)] font-extrabold">กำลังโหลดรูป...</p>
        </div>

        <!-- Error -->
        <div v-else-if="error" class="text-center py-20 bg-red-50 rounded-[2rem] border border-red-100">
          <span class="material-symbols-rounded text-5xl text-red-300 mb-4">wifi_off</span>
          <h3 class="text-xl font-extrabold text-[var(--color-text-dark)] mb-2">โหลดภาพไม่สำเร็จ</h3>
          <p class="text-[var(--color-text-muted)] font-medium mb-6">{{ error }}</p>
          <button @click="fetchImages" class="inline-flex items-center gap-2 px-6 py-3 bg-[var(--color-primary)] text-white rounded-full font-bold hover:bg-[var(--color-primary-mid)] transition-all">
            <span class="material-symbols-rounded text-[20px]">refresh</span>
            ลองใหม่
          </button>
        </div>

        <!-- Empty -->
        <div v-else-if="images.length === 0" class="text-center py-20 bg-white rounded-[2rem] border border-dashed border-[var(--color-sand-dark)]">
          <span class="material-symbols-rounded text-5xl text-[var(--color-text-muted)] mb-4">photo_library</span>
          <h3 class="text-xl font-extrabold text-[var(--color-text-dark)] mb-2">ยังไม่มีรูปจากผู้ร่วมทริป</h3>
          <p class="text-[var(--color-text-muted)] font-medium">รูปจะขึ้นที่นี่เมื่อมีคนกลับจากทริปแล้วรีวิวพร้อมแนบรูป</p>
        </div>

        <template v-else>
          <!-- ที่มาของรูป — บอกตรง ๆ ว่านี่ไม่ใช่รูปที่ทีมงานคัดมา -->
          <p class="text-center text-sm font-bold text-[var(--color-text-muted)] mb-8">
            {{ total.toLocaleString() }} รูป จากรีวิวของผู้ร่วมทริป · เรียงตามวันที่ไปล่าสุด
          </p>

          <!-- Justified rows -->
          <div class="gallery-masonry">
            <button
              v-for="(image, index) in images"
              :key="`${image.review_id}-${index}`"
              type="button"
              class="gallery-item group"
              @click="openLightbox(index)"
            >
              <img
                :src="image.url"
                :alt="`รูปจาก ${image.user_name} ทริป${image.trip_title}`"
                loading="lazy"
                class="gallery-img block transition-transform duration-500 group-hover:scale-[1.04]"
              />
              <div class="gallery-caption">
                <p class="text-white font-extrabold text-sm leading-snug truncate">{{ image.trip_title }}</p>
                <p class="text-white/85 text-xs font-bold mt-0.5 truncate">
                  {{ image.user_name }} · ไปเมื่อ {{ image.travel_month_label }}
                </p>
              </div>
            </button>
          </div>

          <div v-if="hasMore" class="mt-10 text-center">
            <button
              @click="loadMore"
              :disabled="loadingMore"
              class="inline-flex items-center gap-2 px-6 py-3 rounded-full border-2 border-[var(--color-accent)]/25 text-[var(--color-accent)] font-extrabold text-sm hover:bg-[var(--color-accent)] hover:text-white hover:border-[var(--color-accent)] transition-all disabled:opacity-50 active:scale-95"
            >
              <span v-if="loadingMore" class="w-4 h-4 border-2 border-current border-t-transparent rounded-full animate-spin"></span>
              <span v-else class="material-symbols-rounded text-[18px]">expand_more</span>
              {{ loadingMore ? 'กำลังโหลด...' : 'ดูรูปเพิ่ม' }}
            </button>
          </div>
        </template>
      </div>
    </section>

    <!-- Lightbox -->
    <Transition name="lightbox">
      <div v-if="lightboxIndex !== null" class="lightbox" @click.self="closeLightbox">
        <button class="lightbox-btn lightbox-close" @click="closeLightbox" aria-label="ปิด">
          <span class="material-symbols-rounded">close</span>
        </button>
        <button v-if="images.length > 1" class="lightbox-btn lightbox-prev" @click.stop="prev" aria-label="ก่อนหน้า">
          <span class="material-symbols-rounded">chevron_left</span>
        </button>

        <figure class="lightbox-figure">
          <img :src="currentImage.url" :alt="`รูปจาก ${currentImage.user_name}`" class="lightbox-img" />
          <figcaption class="lightbox-caption">
            <p class="text-white font-extrabold text-base">
              {{ currentImage.user_name }} · ไปเมื่อ {{ currentImage.travel_month_label }}
            </p>
            <router-link
              v-if="currentImage.trip_slug"
              :to="`/trips/${currentImage.trip_slug}`"
              class="inline-flex items-center gap-1 text-white/80 text-sm font-bold mt-1 hover:text-white"
              @click="closeLightbox"
            >
              <span class="material-symbols-rounded text-[16px]">location_on</span>
              {{ currentImage.trip_title }}<span v-if="currentImage.location"> · {{ currentImage.location }}</span>
            </router-link>
          </figcaption>
        </figure>

        <button v-if="images.length > 1" class="lightbox-btn lightbox-next" @click.stop="next" aria-label="ถัดไป">
          <span class="material-symbols-rounded">chevron_right</span>
        </button>

        <div v-if="images.length > 1" class="lightbox-counter">{{ lightboxIndex + 1 }} / {{ images.length }}</div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import api from '../lib/axios';

const PER_PAGE = 48;

const images = ref([]);
const total = ref(0);
const page = ref(1);
const hasMore = ref(false);
const loading = ref(true);
const loadingMore = ref(false);
const error = ref('');
const lightboxIndex = ref(null);

const currentImage = computed(() => (lightboxIndex.value !== null ? images.value[lightboxIndex.value] ?? {} : {}));

// ไม่ส่ง trip_id = ขอรูปรีวิวจริงจากทุกทริป
const fetchPage = async (target) => {
  const res = await api.get('/reviews/photos', { params: { page: target, per_page: PER_PAGE } });
  return res.data.data ?? {};
};

const fetchImages = async () => {
  loading.value = true;
  error.value = '';
  page.value = 1;
  try {
    const payload = await fetchPage(1);
    images.value = payload.photos ?? [];
    total.value = payload.total ?? images.value.length;
    hasMore.value = Boolean(payload.has_more);
  } catch (e) {
    error.value = e.response?.data?.message ?? 'เกิดข้อผิดพลาดในการเชื่อมต่อ';
  } finally {
    loading.value = false;
  }
};

const loadMore = async () => {
  if (loadingMore.value || !hasMore.value) return;
  loadingMore.value = true;
  try {
    const next = page.value + 1;
    const payload = await fetchPage(next);
    images.value = [...images.value, ...(payload.photos ?? [])];
    page.value = next;
    hasMore.value = Boolean(payload.has_more);
  } catch (e) {
    console.error('Failed to load more review photos:', e);
  } finally {
    loadingMore.value = false;
  }
};

const openLightbox = (index) => {
  lightboxIndex.value = index;
  document.body.style.overflow = 'hidden';
};

const closeLightbox = () => {
  lightboxIndex.value = null;
  document.body.style.overflow = '';
};

const prev = () => {
  lightboxIndex.value = (lightboxIndex.value - 1 + images.value.length) % images.value.length;
};

const next = () => {
  lightboxIndex.value = (lightboxIndex.value + 1) % images.value.length;
};

const onKeydown = (e) => {
  if (lightboxIndex.value === null) return;
  if (e.key === 'Escape') closeLightbox();
  else if (e.key === 'ArrowLeft') prev();
  else if (e.key === 'ArrowRight') next();
};

onMounted(() => {
  fetchImages();
  window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown);
  document.body.style.overflow = '';
});
</script>

<style scoped>
/* Justified rows — แถวความสูงเท่ากัน จัดกึ่งกลาง คงสัดส่วนภาพเดิม (ไม่ครอป)
   วิธีนี้ทำให้ทุกแถวอยู่กึ่งกลางเสมอ ไม่ว่ารูปจะมีกี่รูป */
.gallery-masonry {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 10px;
}

.gallery-item {
  position: relative;
  display: block;
  height: 220px;
  max-width: 100%;
  flex: 0 0 auto;
  padding: 0;
  border: 1px solid var(--color-sand-dark);
  border-radius: 0.85rem;
  overflow: hidden;
  cursor: pointer;
  background: var(--color-sand-dark);
  transition: transform 0.35s ease;
}
@media (min-width: 640px) { .gallery-item { height: 300px; } }
@media (min-width: 1024px) { .gallery-item { height: 360px; } }
@media (min-width: 1440px) { .gallery-item { height: 400px; } }

.gallery-img {
  height: 100%;
  width: auto;
  max-width: 100%;
}
.gallery-item:hover { transform: translateY(-3px); }

/* เครดิตติดทุกรูป — รูปมาจากคน ไม่ใช่จากแบรนด์ */
.gallery-caption {
  position: absolute;
  inset: auto 0 0 0;
  padding: 26px 12px 11px;
  text-align: left;
  background: linear-gradient(to top, rgba(0, 0, 0, 0.78), rgba(0, 0, 0, 0));
  opacity: 0;
  transform: translateY(8px);
  transition: opacity 0.3s ease, transform 0.3s ease;
}
.gallery-item:hover .gallery-caption { opacity: 1; transform: translateY(0); }
/* จอสัมผัสไม่มี hover — โชว์เครดิตค้างไว้เลย */
@media (hover: none) {
  .gallery-caption { opacity: 1; transform: none; }
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
  flex-direction: column;
  align-items: center;
}
.lightbox-img {
  max-width: 100%;
  max-height: 78vh;
  object-fit: contain;
  border-radius: 0.75rem;
}
.lightbox-caption { margin-top: 16px; text-align: center; }

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
