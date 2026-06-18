<template>
  <div class="gallery-page font-anuphan bg-[var(--color-sand)] min-h-screen">
    <!-- Hero -->
    <section class="relative min-h-[300px] flex items-center overflow-hidden -mt-16 bg-[var(--color-primary)]">
      <div class="absolute inset-0">
        <img src="/images/landscape.webp" alt="ภาพประทับใจ" class="w-full h-full object-cover" />
        <div class="absolute inset-0 bg-black/40"></div>
      </div>
      <div class="relative z-10 w-full px-6 md:px-8 py-24 md:py-32 text-center flex flex-col items-center">
        <div class="w-16 h-1.5 bg-[var(--color-accent)] mb-6 rounded-full shadow-lg"></div>
        <h1 class="text-4xl md:text-6xl font-black text-white leading-tight mb-4 tracking-tight drop-shadow-md">
          ภาพประทับใจ
        </h1>
        <p class="text-lg md:text-xl text-white/80 font-bold max-w-2xl mx-auto tracking-wide">
          เก็บทุกช่วงเวลาความประทับใจจากการเดินทางไปกับลุยเลเขา
        </p>
      </div>
    </section>

    <section class="py-14 md:py-20">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 md:px-8">

        <!-- Loading -->
        <div v-if="loading" class="text-center py-24">
          <div class="w-14 h-14 border-4 border-white border-t-[var(--color-accent)] rounded-full animate-spin mx-auto"></div>
          <p class="mt-6 text-[var(--color-text-dark)] font-extrabold">กำลังโหลดภาพประทับใจ...</p>
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
          <h3 class="text-xl font-extrabold text-[var(--color-text-dark)] mb-2">ยังไม่มีภาพในแกลเลอรี</h3>
          <p class="text-[var(--color-text-muted)] font-medium">เรากำลังคัดสรรภาพความประทับใจมาให้ชมเร็วๆ นี้</p>
        </div>

        <!-- Masonry grid -->
        <div v-else class="gallery-masonry">
          <button
            v-for="(image, index) in images"
            :key="image.id"
            type="button"
            class="gallery-item group"
            @click="openLightbox(index)"
          >
            <img
              :src="image.image_url"
              :alt="image.caption || 'ภาพประทับใจลุยเลเขา'"
              loading="lazy"
              class="gallery-img block transition-transform duration-500 group-hover:scale-[1.04]"
            />
            <div
              v-if="image.caption || image.location"
              class="gallery-caption"
            >
              <p v-if="image.caption" class="text-white font-extrabold text-sm leading-snug drop-shadow">{{ image.caption }}</p>
              <p v-if="image.location" class="text-white/85 text-xs font-bold flex items-center gap-1 mt-0.5">
                <span class="material-symbols-rounded text-[14px]">location_on</span>
                {{ image.location }}
              </p>
            </div>
            <span class="gallery-zoom">
              <span class="material-symbols-rounded text-[18px]">zoom_in</span>
            </span>
          </button>
        </div>
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
          <img :src="currentImage.image_url" :alt="currentImage.caption || 'ภาพประทับใจ'" class="lightbox-img" />
          <figcaption v-if="currentImage.caption || currentImage.location" class="lightbox-caption">
            <p v-if="currentImage.caption" class="text-white font-extrabold text-base">{{ currentImage.caption }}</p>
            <p v-if="currentImage.location" class="text-white/80 text-sm font-bold flex items-center justify-center gap-1 mt-1">
              <span class="material-symbols-rounded text-[16px]">location_on</span>
              {{ currentImage.location }}
            </p>
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

const images = ref([]);
const loading = ref(true);
const error = ref('');
const lightboxIndex = ref(null);

const currentImage = computed(() => (lightboxIndex.value !== null ? images.value[lightboxIndex.value] : {}));

const fetchImages = async () => {
  loading.value = true;
  error.value = '';
  try {
    const res = await api.get('/gallery');
    images.value = res.data.data ?? res.data ?? [];
  } catch (e) {
    error.value = e.response?.data?.message ?? 'เกิดข้อผิดพลาดในการเชื่อมต่อ';
  } finally {
    loading.value = false;
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
  border: none;
  border-radius: 0.85rem;
  overflow: hidden;
  cursor: pointer;
  background: var(--color-sand-dark);
  box-shadow: 0 2px 10px rgba(13, 43, 30, 0.05);
  transition: box-shadow 0.35s ease, transform 0.35s ease;
}
@media (min-width: 640px) { .gallery-item { height: 300px; } }
@media (min-width: 1024px) { .gallery-item { height: 360px; } }
@media (min-width: 1440px) { .gallery-item { height: 400px; } }

.gallery-img {
  height: 100%;
  width: auto;
  max-width: 100%;
}
.gallery-item:hover {
  transform: translateY(-3px);
  box-shadow: 0 16px 40px rgba(13, 43, 30, 0.18);
}
/* เคลือบเงาบางๆ ให้ขอบภาพดูคมและทันสมัยขึ้น */
.gallery-item::after {
  content: '';
  position: absolute;
  inset: 0;
  border-radius: inherit;
  box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08);
  pointer-events: none;
}

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

.gallery-zoom {
  position: absolute;
  top: 10px;
  right: 10px;
  width: 30px;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  color: #fff;
  background: rgba(0, 0, 0, 0.4);
  backdrop-filter: blur(6px);
  opacity: 0;
  transform: scale(0.8);
  transition: opacity 0.3s ease, transform 0.3s ease;
}
.gallery-item:hover .gallery-zoom { opacity: 1; transform: scale(1); }
.gallery-zoom .material-symbols-rounded { font-size: 18px; }

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
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
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
