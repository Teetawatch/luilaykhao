<template>
  <div class="reviews-page font-anuphan bg-[var(--color-sand)] min-h-screen pb-20">

    <!-- ══════════════════════════════════════════
         HEADER
    ══════════════════════════════════════════ -->
    <section class="bg-white border-b border-[var(--color-sand-dark)]">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-10 md:py-14">
        <p class="inline-flex items-center gap-2 text-[13px] font-bold text-[var(--color-accent)]">
          <span class="w-6 h-[2px] rounded-full bg-[var(--color-accent)]"></span>
          รีวิว
        </p>
        <h1 class="mt-3 text-3xl md:text-5xl font-extrabold text-[var(--color-text-dark)] tracking-tight">
          รีวิวจากนักเดินทาง
        </h1>
        <p class="mt-3 max-w-2xl text-base md:text-lg text-[var(--color-text-mid)] leading-relaxed">
          คะแนนและความเห็นจากคนที่ไปทริปกับเรา ทั้งเรื่องที่ชอบและเรื่องที่อยากให้ปรับ
        </p>
      </div>
    </section>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-8 md:pt-10">
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8 items-start">

        <!-- ══════════════════════════════════════════
             OVERVIEW (sticky on desktop)
        ══════════════════════════════════════════ -->
        <aside class="lg:col-span-4 lg:sticky lg:top-24 space-y-4">
          <div v-if="stats && stats.total > 0" class="bg-white rounded-3xl border border-[var(--color-sand-dark)] p-6">
            <div class="flex items-center gap-4">
              <span class="text-5xl font-extrabold leading-none tracking-tight text-[var(--color-text-dark)] tabular-nums">{{ stats.average.toFixed(1) }}</span>
              <div>
                <StarRow :value="stats.average" size="20" />
                <p class="mt-1 text-[13px] font-semibold text-[var(--color-text-muted)]">จาก {{ stats.total.toLocaleString() }} รีวิว</p>
              </div>
            </div>

            <!-- Distribution — กดแถวเพื่อกรอง -->
            <div class="mt-6 space-y-1">
              <button
                v-for="level in LEVELS"
                :key="level"
                type="button"
                class="dist-row"
                :class="{ 'dist-row--active': filters.rating === level }"
                :disabled="!stats.distribution[level]"
                :aria-pressed="filters.rating === level"
                @click="setFilter({ rating: filters.rating === level ? null : level })"
              >
                <span class="w-8 flex items-center gap-0.5 text-[13px] font-bold text-[var(--color-text-dark)]">
                  {{ level }}<span class="material-symbols-rounded text-[14px] text-[var(--color-gold)]" :style="FILLED">star</span>
                </span>
                <span class="flex-1 h-2 rounded-full bg-[var(--color-sand)] overflow-hidden">
                  <span class="block h-full rounded-full bg-[var(--color-gold)] transition-[width] duration-500" :style="{ width: distPercent(level) + '%' }"></span>
                </span>
                <span class="w-10 text-right text-[12px] font-bold text-[var(--color-text-muted)] tabular-nums">{{ (stats.distribution[level] || 0).toLocaleString() }}</span>
              </button>
            </div>

            <!-- Category averages -->
            <div v-if="activeCategories.length" class="mt-6 pt-5 border-t border-[var(--color-sand-dark)] grid grid-cols-2 gap-3">
              <div v-for="cat in activeCategories" :key="cat.key" class="rounded-2xl bg-[var(--color-sand)] p-3">
                <div class="flex items-center gap-1.5 text-[12px] font-bold text-[var(--color-text-muted)]">
                  <span class="material-symbols-rounded text-[16px] text-[var(--color-accent)]">{{ cat.icon }}</span>
                  {{ cat.label }}
                </div>
                <p class="mt-1 text-lg font-extrabold text-[var(--color-text-dark)] tabular-nums">
                  {{ cat.average.toFixed(1) }}<span class="text-[12px] font-bold text-[var(--color-text-muted)]">/5</span>
                </p>
              </div>
            </div>

            <p v-if="stats.with_reply" class="mt-5 flex items-center gap-1.5 text-[12px] font-semibold text-[var(--color-text-muted)]">
              <span class="material-symbols-rounded text-[16px]">forum</span>
              ทีมงานตอบกลับแล้ว {{ stats.with_reply.toLocaleString() }} รีวิว
            </p>
          </div>

          <div v-else-if="statsLoading" class="h-72 rounded-3xl border border-[var(--color-sand-dark)] bg-white animate-pulse" aria-hidden="true"></div>

          <!-- CTA (desktop) -->
          <div class="hidden lg:block rounded-3xl bg-[var(--color-primary)] p-6 text-white">
            <p class="font-extrabold text-lg">เคยไปทริปกับเราแล้ว?</p>
            <p class="mt-1 text-sm text-white/70 leading-relaxed">เล่าให้คนที่กำลังตัดสินใจฟังหน่อย รีวิวของคุณช่วยเขาได้มาก</p>
            <router-link to="/my-reviews" class="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-[var(--color-primary)] hover:bg-[var(--color-sand)] transition-colors">
              <span class="material-symbols-rounded text-[18px]">rate_review</span>
              เขียนรีวิวของฉัน
            </router-link>
          </div>
        </aside>

        <!-- ══════════════════════════════════════════
             LIST
        ══════════════════════════════════════════ -->
        <main ref="listTop" class="lg:col-span-8 min-w-0 scroll-mt-24">

          <!-- Filter chips -->
          <div class="-mx-4 px-4 sm:mx-0 sm:px-0 overflow-x-auto scrollbar-none">
            <div class="flex gap-2 pb-1 w-max sm:w-auto sm:flex-wrap">
              <button
                type="button"
                class="chip"
                :class="{ 'chip--active': !filters.rating && !filters.media }"
                @click="setFilter({ rating: null, media: false })"
              >
                ทั้งหมด
                <span v-if="stats" class="chip-count">{{ stats.total.toLocaleString() }}</span>
              </button>
              <button
                type="button"
                class="chip"
                :class="{ 'chip--active': filters.media }"
                :disabled="stats && !stats.with_media"
                @click="setFilter({ media: !filters.media })"
              >
                <span class="material-symbols-rounded text-[16px]">photo_library</span>
                มีรูป/วิดีโอ
                <span v-if="stats" class="chip-count">{{ stats.with_media.toLocaleString() }}</span>
              </button>
              <button
                v-for="level in LEVELS"
                :key="level"
                type="button"
                class="chip"
                :class="{ 'chip--active': filters.rating === level }"
                :disabled="stats && !stats.distribution[level]"
                @click="setFilter({ rating: filters.rating === level ? null : level })"
              >
                {{ level }}<span class="material-symbols-rounded text-[15px] text-[var(--color-gold)]" :style="FILLED">star</span>
                <span v-if="stats" class="chip-count">{{ (stats.distribution[level] || 0).toLocaleString() }}</span>
              </button>
            </div>
          </div>

          <p v-if="meta && !error" class="mt-4 text-[13px] font-semibold text-[var(--color-text-muted)]">
            {{ resultLabel }}
          </p>

          <!-- First load -->
          <div v-if="loading && !reviews.length" class="mt-4 space-y-4" aria-hidden="true">
            <div v-for="n in 3" :key="n" class="rounded-3xl border border-[var(--color-sand-dark)] bg-white p-6 animate-pulse">
              <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-full bg-[var(--color-sand)]"></div>
                <div class="space-y-2 flex-1">
                  <div class="h-3.5 w-32 rounded bg-[var(--color-sand)]"></div>
                  <div class="h-3 w-48 rounded bg-[var(--color-sand)]"></div>
                </div>
              </div>
              <div class="mt-5 space-y-2">
                <div class="h-3 rounded bg-[var(--color-sand)]"></div>
                <div class="h-3 w-4/5 rounded bg-[var(--color-sand)]"></div>
              </div>
            </div>
          </div>

          <!-- Error -->
          <div v-else-if="error" class="mt-4 rounded-3xl border border-[var(--color-sand-dark)] bg-white px-6 py-14 text-center">
            <span class="material-symbols-rounded text-5xl text-[var(--color-text-muted)]/50">wifi_off</span>
            <h2 class="mt-3 text-lg font-extrabold text-[var(--color-text-dark)]">โหลดรีวิวไม่สำเร็จ</h2>
            <p class="mt-1 text-sm text-[var(--color-text-muted)]">{{ error }}</p>
            <button type="button" class="btn-primary mt-5" @click="fetchReviews">
              <span class="material-symbols-rounded text-[18px]">refresh</span>
              ลองใหม่
            </button>
          </div>

          <!-- Empty -->
          <div v-else-if="!reviews.length" class="mt-4 rounded-3xl border border-dashed border-[var(--color-sand-dark)] bg-white px-6 py-14 text-center">
            <span class="material-symbols-rounded text-5xl text-[var(--color-text-muted)]/50">rate_review</span>
            <h2 class="mt-3 text-lg font-extrabold text-[var(--color-text-dark)]">{{ hasFilter ? 'ไม่มีรีวิวที่ตรงกับตัวกรองนี้' : 'ยังไม่มีรีวิว' }}</h2>
            <p class="mt-1 text-sm text-[var(--color-text-muted)]">{{ hasFilter ? 'ลองเลือกตัวกรองอื่นดูนะครับ' : 'รีวิวที่ผ่านการอนุมัติแล้วจะแสดงที่หน้านี้' }}</p>
            <button v-if="hasFilter" type="button" class="btn-ghost mt-5" @click="setFilter({ rating: null, media: false })">ล้างตัวกรอง</button>
          </div>

          <!-- Reviews -->
          <div v-else class="mt-4 space-y-4 transition-opacity duration-200" :class="{ 'opacity-50 pointer-events-none': loading }" :aria-busy="loading">
            <article
              v-for="review in reviews"
              :key="review.id"
              class="rounded-3xl border border-[var(--color-sand-dark)] bg-white p-5 md:p-6"
            >
              <header class="flex items-start gap-3">
                <div class="w-11 h-11 rounded-full bg-[var(--color-primary)] flex items-center justify-center text-white font-bold overflow-hidden shrink-0">
                  <img v-if="review.avatar" :src="review.avatar" alt="" loading="lazy" class="w-full h-full object-cover" />
                  <span v-else>{{ avatarInitial(review) }}</span>
                </div>
                <div class="min-w-0 flex-1">
                  <div class="flex items-center gap-1.5 min-w-0">
                    <span class="font-bold text-[var(--color-text-dark)] truncate">{{ review.user_name }}</span>
                    <TierBadge :tier="review.user?.tier" :label="review.user?.tier_label" size="sm" />
                  </div>
                  <p class="flex items-center gap-1.5 text-[13px] text-[var(--color-text-muted)] min-w-0">
                    <time :datetime="review.created_at" :title="thaiShort(review.created_at)" class="shrink-0">{{ relativeDate(review.created_at) }}</time>
                    <template v-if="review.trip_title && review.trip_title !== '-'">
                      <span aria-hidden="true">·</span>
                      <router-link
                        v-if="review.trip_slug"
                        :to="`/trips/${review.trip_slug}`"
                        class="truncate font-semibold hover:text-[var(--color-accent)] transition-colors"
                      >{{ review.trip_title }}</router-link>
                      <span v-else class="truncate">{{ review.trip_title }}</span>
                    </template>
                  </p>
                </div>
              </header>

              <div class="mt-4 flex items-center gap-2">
                <StarRow :value="review.rating" size="18" />
                <span class="text-[13px] font-bold text-[var(--color-text-dark)]">{{ RATING_WORDS[review.rating] }}</span>
              </div>

              <div v-if="review.comment" class="mt-2.5">
                <p
                  class="text-[15px] text-[var(--color-text-mid)] leading-relaxed whitespace-pre-line break-words"
                  :class="{ 'line-clamp-6': isLong(review) && !expanded[review.id] }"
                >{{ review.comment }}</p>
                <button
                  v-if="isLong(review)"
                  type="button"
                  class="mt-1 text-sm font-bold text-[var(--color-accent)] hover:underline"
                  @click="expanded[review.id] = !expanded[review.id]"
                >{{ expanded[review.id] ? 'ย่อลง' : 'อ่านต่อ' }}</button>
              </div>

              <!-- Sub-ratings -->
              <div v-if="review.categories.length" class="mt-4 flex flex-wrap gap-1.5">
                <span
                  v-for="cat in review.categories"
                  :key="cat.key"
                  class="inline-flex items-center gap-1 rounded-full bg-[var(--color-sand)] px-2.5 py-1 text-[12px] font-bold text-[var(--color-text-mid)]"
                >
                  <span class="material-symbols-rounded text-[14px] text-[var(--color-accent)]">{{ cat.icon }}</span>
                  {{ cat.label }}
                  <span class="text-[var(--color-gold-dark)] tabular-nums">{{ cat.value }}/5</span>
                </span>
              </div>

              <!-- Media -->
              <div v-if="review.media.length" class="mt-4 grid grid-cols-4 sm:grid-cols-5 gap-2 max-w-md">
                <button
                  v-for="(item, index) in review.media.slice(0, MEDIA_PREVIEW)"
                  :key="index"
                  type="button"
                  class="media-thumb group relative aspect-square rounded-xl overflow-hidden bg-[var(--color-sand)]"
                  :aria-label="item.type === 'video' ? 'เล่นวิดีโอรีวิว' : `ดูรูปที่ ${index + 1}`"
                  @click="openLightbox(review, index)"
                >
                  <img
                    v-if="item.type === 'image'"
                    :src="item.url"
                    alt=""
                    loading="lazy"
                    class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
                  />
                  <template v-else>
                    <video :src="`${item.url}#t=0.1`" class="w-full h-full object-cover" muted playsinline preload="metadata"></video>
                    <span class="absolute inset-0 flex items-center justify-center bg-black/25">
                      <span class="material-symbols-rounded text-white text-[30px]" :style="FILLED">play_circle</span>
                    </span>
                  </template>
                  <span
                    v-if="index === MEDIA_PREVIEW - 1 && review.media.length > MEDIA_PREVIEW"
                    class="absolute inset-0 flex items-center justify-center bg-black/55 text-white text-lg font-extrabold"
                  >+{{ review.media.length - MEDIA_PREVIEW + 1 }}</span>
                </button>
              </div>

              <!-- Admin reply -->
              <div v-if="review.admin_reply" class="mt-4 rounded-2xl bg-[var(--color-sand)] p-4">
                <p class="flex items-center gap-1.5 text-[12px] font-bold text-[var(--color-accent)]">
                  <span class="material-symbols-rounded text-[16px]" :style="FILLED">support_agent</span>
                  ตอบกลับจากทีมงาน{{ review.admin_replied_by ? ` · ${review.admin_replied_by}` : '' }}
                </p>
                <p class="mt-1.5 text-sm text-[var(--color-text-dark)] leading-relaxed whitespace-pre-line break-words">{{ review.admin_reply }}</p>
              </div>
            </article>
          </div>

          <!-- Pagination -->
          <nav v-if="!error && lastPage > 1" class="mt-8 flex justify-center items-center gap-1.5" aria-label="หน้ารีวิว">
            <button type="button" class="page-btn" :disabled="currentPage <= 1 || loading" aria-label="หน้าก่อนหน้า" @click="goToPage(currentPage - 1)">
              <span class="material-symbols-rounded">chevron_left</span>
            </button>
            <template v-for="item in paginationItems" :key="item.key">
              <span v-if="!item.page" class="w-8 text-center text-[var(--color-text-muted)]">…</span>
              <button
                v-else
                type="button"
                class="page-btn"
                :class="{ 'page-btn--active': item.page === currentPage }"
                :aria-current="item.page === currentPage ? 'page' : undefined"
                :disabled="loading"
                @click="goToPage(item.page)"
              >{{ item.page }}</button>
            </template>
            <button type="button" class="page-btn" :disabled="currentPage >= lastPage || loading" aria-label="หน้าถัดไป" @click="goToPage(currentPage + 1)">
              <span class="material-symbols-rounded">chevron_right</span>
            </button>
          </nav>

          <!-- CTA (mobile) -->
          <div class="lg:hidden mt-8 rounded-3xl bg-[var(--color-primary)] p-6 text-white">
            <p class="font-extrabold text-lg">เคยไปทริปกับเราแล้ว?</p>
            <p class="mt-1 text-sm text-white/70 leading-relaxed">เล่าให้คนที่กำลังตัดสินใจฟังหน่อย รีวิวของคุณช่วยเขาได้มาก</p>
            <div class="mt-4 flex flex-wrap gap-2">
              <router-link to="/my-reviews" class="inline-flex items-center gap-1.5 rounded-xl bg-white px-4 py-2.5 text-sm font-bold text-[var(--color-primary)]">
                <span class="material-symbols-rounded text-[18px]">rate_review</span>
                เขียนรีวิวของฉัน
              </router-link>
              <router-link to="/trips" class="inline-flex items-center rounded-xl border border-white/25 px-4 py-2.5 text-sm font-bold text-white">
                ดูทริปที่เปิดรับ
              </router-link>
            </div>
          </div>
        </main>
      </div>
    </div>

    <!-- ══════════════════════════════════════════
         LIGHTBOX
    ══════════════════════════════════════════ -->
    <Teleport to="body">
      <Transition name="lightbox">
        <div
          v-if="lightbox"
          class="lightbox"
          role="dialog"
          aria-modal="true"
          aria-label="รูปและวิดีโอจากรีวิว"
          @click.self="closeLightbox"
          @touchstart.passive="onTouchStart"
          @touchend="onTouchEnd"
        >
          <div class="lightbox-top">
            <span class="truncate">{{ lightbox.author }}</span>
            <span v-if="lightbox.items.length > 1" class="tabular-nums shrink-0">{{ lightbox.index + 1 }} / {{ lightbox.items.length }}</span>
            <button ref="lightboxClose" type="button" class="lightbox-btn ml-auto" aria-label="ปิด" @click="closeLightbox">
              <span class="material-symbols-rounded">close</span>
            </button>
          </div>

          <button v-if="lightbox.items.length > 1" type="button" class="lightbox-btn lightbox-prev" aria-label="ก่อนหน้า" @click.stop="stepMedia(-1)">
            <span class="material-symbols-rounded">chevron_left</span>
          </button>

          <div class="lightbox-figure" @click.self="closeLightbox">
            <img v-if="currentMedia.type === 'image'" :key="`img-${lightbox.index}`" :src="currentMedia.url" alt="" class="lightbox-media" />
            <video v-else :key="`vid-${lightbox.index}`" :src="currentMedia.url" class="lightbox-media lightbox-video" controls autoplay playsinline></video>
          </div>

          <button v-if="lightbox.items.length > 1" type="button" class="lightbox-btn lightbox-next" aria-label="ถัดไป" @click.stop="stepMedia(1)">
            <span class="material-symbols-rounded">chevron_right</span>
          </button>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, defineComponent, h, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../lib/axios';
import { thaiShort } from '../lib/thaiDate';
import TierBadge from '../components/TierBadge.vue';

const route = useRoute();
const router = useRouter();

const FILLED = "font-variation-settings:'FILL' 1";
const LEVELS = [5, 4, 3, 2, 1];
const PER_PAGE = 10;
const MEDIA_PREVIEW = 5;
const LONG_COMMENT = 320;

const RATING_WORDS = { 5: 'ดีมาก', 4: 'ดี', 3: 'พอใช้', 2: 'ควรปรับปรุง', 1: 'ไม่ประทับใจ' };

const CATEGORY_META = [
  { key: 'guide', label: 'ไกด์นำเที่ยว', icon: 'hiking' },
  { key: 'vehicle', label: 'ยานพาหนะ', icon: 'directions_bus' },
  { key: 'food', label: 'อาหาร', icon: 'restaurant' },
  { key: 'value', label: 'ความคุ้มค่า', icon: 'savings' },
];

/** ดาว 5 ดวง — เต็ม/ครึ่ง/ว่าง ตามค่าเฉลี่ย */
const StarRow = defineComponent({
  props: { value: { type: Number, default: 0 }, size: { type: [String, Number], default: 18 } },
  setup(props) {
    return () => h('span', { class: 'inline-flex items-center', role: 'img', 'aria-label': `${props.value.toFixed(1)} จาก 5 ดาว` },
      [1, 2, 3, 4, 5].map((i) => {
        const diff = props.value - i + 1;
        const icon = diff >= 0.75 ? 'star' : diff >= 0.25 ? 'star_half' : 'star';
        const filled = diff >= 0.25;
        return h('span', {
          class: ['material-symbols-rounded', filled ? 'text-[var(--color-gold)]' : 'text-[var(--color-sand-dark)]'],
          style: `font-size:${props.size}px;${filled ? FILLED : ''}`,
          'aria-hidden': 'true',
        }, icon);
      }));
  },
});

// ─── Filters live in the URL ───
// ?rating=5&media=1&page=2 — กดย้อนกลับแล้วได้ตัวกรองเดิม และแชร์ลิงก์ตัวกรองได้
const filters = computed(() => {
  const rating = Number(route.query.rating);
  return {
    rating: LEVELS.includes(rating) ? rating : null,
    media: route.query.media === '1',
    page: Math.max(1, Number.parseInt(route.query.page, 10) || 1),
  };
});

const hasFilter = computed(() => !!(filters.value.rating || filters.value.media));

function buildQuery({ rating, media, page }) {
  return {
    ...route.query,
    rating: rating || undefined,
    media: media ? '1' : undefined,
    page: page > 1 ? String(page) : undefined,
  };
}

function setFilter(patch) {
  // เปลี่ยนตัวกรองแล้วกลับไปหน้า 1 เสมอ — หน้า 3 ของตัวกรองเดิมอาจไม่มีในตัวกรองใหม่
  router.push({ query: buildQuery({ ...filters.value, ...patch, page: 1 }) });
}

function goToPage(page) {
  if (page < 1 || page > lastPage.value || page === currentPage.value) return;
  router.push({ query: buildQuery({ ...filters.value, page }) });
}

// ─── Data ───
const reviews = ref([]);
const meta = ref(null);
const stats = ref(null);
const statsLoading = ref(true);
const loading = ref(true);
const error = ref('');
const expanded = reactive({});
const listTop = ref(null);

const currentPage = computed(() => Number(meta.value?.current_page || 1));
const lastPage = computed(() => Number(meta.value?.last_page || 1));

const resultLabel = computed(() => {
  const total = Number(meta.value?.total || 0);
  const parts = [];
  if (filters.value.rating) parts.push(`${filters.value.rating} ดาว`);
  if (filters.value.media) parts.push('มีรูป/วิดีโอ');
  return `${total.toLocaleString()} รีวิว${parts.length ? ` · ${parts.join(' · ')}` : ''}`;
});

const activeCategories = computed(() => {
  if (!stats.value?.categories) return [];
  return CATEGORY_META
    .map((c) => ({ ...c, ...(stats.value.categories[c.key] || {}) }))
    .filter((c) => c.count > 0 && c.average != null);
});

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
  } else {
    pages.push(1);
    if (current > 3) pages.push(null);
    for (let page = Math.max(2, current - 1); page <= Math.min(last - 1, current + 1); page += 1) pages.push(page);
    if (current < last - 2) pages.push(null);
    pages.push(last);
  }
  return pages.map((page, index) => ({ page, key: page ? `page-${page}` : `gap-${index}` }));
});

async function fetchStats() {
  try {
    const res = await api.get('/reviews/stats');
    stats.value = res.data?.data || null;
  } catch {
    stats.value = null;
  } finally {
    statsLoading.value = false;
  }
}

// กดตัวกรองรัว ๆ แล้วคำตอบกลับมาไม่ตรงลำดับ — นับรอบไว้ แล้วทิ้งคำตอบของรอบที่ถูกแทนแล้ว
let requestSeq = 0;

async function fetchReviews() {
  const seq = ++requestSeq;
  const { rating, media, page } = filters.value;
  loading.value = true;
  error.value = '';

  try {
    const params = { page, per_page: PER_PAGE };
    if (rating) params.rating = rating;
    if (media) params.has_media = 1;

    const res = await api.get('/reviews', { params });
    if (seq !== requestSeq) return;

    reviews.value = (res.data?.data || []).map(normalizeReview);
    meta.value = res.data?.meta || null;

    // หน้าเกินจำนวนที่มี (ลิงก์เก่า / รีวิวถูกลบ) — พาไปหน้าสุดท้ายแทนหน้าว่าง
    if (!reviews.value.length && page > 1 && meta.value?.last_page && page > meta.value.last_page) {
      router.replace({ query: buildQuery({ ...filters.value, page: meta.value.last_page }) });
    }
  } catch (err) {
    if (seq !== requestSeq) return;
    error.value = err.response?.data?.message || 'ตรวจสอบอินเทอร์เน็ตแล้วลองใหม่อีกครั้ง';
  } finally {
    if (seq === requestSeq) loading.value = false;
  }
}

function normalizeReview(review) {
  return {
    ...review,
    avatar: mediaUrl(review.user_avatar || review.user?.avatar_url || ''),
    media: [
      ...(review.images || []).map((url) => ({ type: 'image', url: mediaUrl(url) })),
      ...(review.videos || []).map((url) => ({ type: 'video', url: mediaUrl(url) })),
    ].filter((item) => item.url),
    categories: CATEGORY_META
      .map((c) => ({ ...c, value: review[`rating_${c.key}`] }))
      .filter((c) => c.value != null && c.value > 0),
  };
}

function isLong(review) {
  const text = review.comment || '';
  return text.length > LONG_COMMENT || text.split('\n').length > 6;
}

watch(
  () => [filters.value.rating, filters.value.media, filters.value.page],
  async (_, old) => {
    await fetchReviews();
    // เลื่อนกลับไปหัวรายการเมื่อเปลี่ยนหน้า/ตัวกรอง (ไม่ใช่ตอนเปิดหน้าครั้งแรก)
    if (old && listTop.value && listTop.value.getBoundingClientRect().top < 0) {
      await nextTick();
      listTop.value.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  },
  { immediate: true },
);

// ─── Lightbox ───
const lightbox = ref(null);
const lightboxClose = ref(null);
const currentMedia = computed(() => lightbox.value?.items[lightbox.value.index] || {});
let lastFocused = null;

async function openLightbox(review, index) {
  lastFocused = document.activeElement;
  lightbox.value = { items: review.media, index, author: review.user_name };
  document.body.style.overflow = 'hidden';
  await nextTick();
  lightboxClose.value?.focus();
}

function closeLightbox() {
  lightbox.value = null;
  document.body.style.overflow = '';
  lastFocused?.focus?.();
}

function stepMedia(delta) {
  const total = lightbox.value.items.length;
  lightbox.value.index = (lightbox.value.index + delta + total) % total;
}

function onKeydown(e) {
  if (!lightbox.value) return;
  if (e.key === 'Escape') closeLightbox();
  else if (e.key === 'ArrowLeft') stepMedia(-1);
  else if (e.key === 'ArrowRight') stepMedia(1);
}

let touchX = null;
function onTouchStart(e) {
  touchX = e.touches[0]?.clientX ?? null;
}
function onTouchEnd(e) {
  if (touchX === null || !lightbox.value || lightbox.value.items.length < 2) return;
  const dx = (e.changedTouches[0]?.clientX ?? touchX) - touchX;
  touchX = null;
  if (Math.abs(dx) > 50) stepMedia(dx < 0 ? 1 : -1);
}

// ─── Helpers ───
function mediaUrl(value) {
  if (!value) return '';
  const raw = String(value);
  if (raw.startsWith('http://') || raw.startsWith('https://')) return raw;
  return `${window.location.origin}/${raw.replace(/^\/+/, '')}`;
}

function avatarInitial(review) {
  return (review.user_name || '?').trim().charAt(0).toUpperCase();
}

/** วันนี้ / เมื่อวาน / N วันที่แล้ว / N สัปดาห์ที่แล้ว แล้วเป็นวันที่ไทยหลัง 30 วัน — แบบเดียวกับในแอป */
function relativeDate(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  const startOf = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
  const days = Math.round((startOf(new Date()) - startOf(date)) / 86400000);
  if (days <= 0) return 'วันนี้';
  if (days === 1) return 'เมื่อวาน';
  if (days < 7) return `${days} วันที่แล้ว`;
  if (days < 30) return `${Math.floor(days / 7)} สัปดาห์ที่แล้ว`;
  return thaiShort(date);
}

onMounted(() => {
  fetchStats();
  window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown);
  document.body.style.overflow = '';
});
</script>

<style scoped>
.btn-primary {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem 1.5rem;
  border-radius: 0.875rem;
  font-size: 0.875rem;
  font-weight: 700;
  color: #fff;
  background-color: var(--color-primary);
  transition: background-color 0.2s ease;
}
.btn-primary:hover { background-color: var(--color-accent); }

.btn-ghost {
  display: inline-flex;
  align-items: center;
  padding: 0.625rem 1.25rem;
  border-radius: 0.875rem;
  font-size: 0.875rem;
  font-weight: 700;
  color: var(--color-text-dark);
  border: 1px solid var(--color-sand-dark);
  transition: border-color 0.2s ease, color 0.2s ease;
}
.btn-ghost:hover { border-color: var(--color-accent); color: var(--color-accent); }

.dist-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.375rem 0.5rem;
  margin: 0 -0.5rem;
  width: calc(100% + 1rem);
  border-radius: 0.625rem;
  transition: background-color 0.2s ease;
}
.dist-row:hover:not(:disabled) { background-color: var(--color-sand); }
.dist-row:disabled { opacity: 0.45; cursor: default; }
.dist-row--active,
.dist-row--active:hover:not(:disabled) { background-color: color-mix(in srgb, var(--color-gold) 14%, white); }

.chip {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.5rem 0.875rem;
  border-radius: 9999px;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  font-size: 0.8125rem;
  font-weight: 700;
  color: var(--color-text-mid);
  white-space: nowrap;
  transition: border-color 0.2s ease, background-color 0.2s ease, color 0.2s ease;
}
.chip:hover:not(:disabled) { border-color: var(--color-accent); }
.chip:disabled { opacity: 0.4; cursor: not-allowed; }
.chip--active,
.chip--active:hover:not(:disabled) {
  border-color: var(--color-primary);
  background-color: var(--color-primary);
  color: #fff;
}
.chip-count {
  margin-left: 0.125rem;
  font-size: 0.75rem;
  font-weight: 700;
  opacity: 0.6;
  font-variant-numeric: tabular-nums;
}

.page-btn {
  min-width: 2.5rem;
  height: 2.5rem;
  padding: 0 0.75rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.75rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  font-size: 0.875rem;
  font-weight: 700;
  color: var(--color-text-dark);
  transition: border-color 0.2s ease, color 0.2s ease;
}
.page-btn:hover:not(:disabled) { border-color: var(--color-accent); color: var(--color-accent); }
.page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.page-btn--active,
.page-btn--active:disabled {
  opacity: 1;
  border-color: var(--color-primary);
  background-color: var(--color-primary);
  color: #fff;
  cursor: default;
}

.media-thumb:focus-visible,
.chip:focus-visible,
.dist-row:focus-visible,
.page-btn:focus-visible {
  outline: 2px solid var(--color-accent);
  outline-offset: 2px;
}

.scrollbar-none { scrollbar-width: none; }
.scrollbar-none::-webkit-scrollbar { display: none; }

/* Lightbox */
.lightbox {
  position: fixed;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 72px 16px 24px;
  background: rgba(8, 12, 10, 0.94);
}
.lightbox-top {
  position: absolute;
  top: 0;
  inset-inline: 0;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 12px 16px 12px 20px;
  color: rgba(255, 255, 255, 0.8);
  font-size: 14px;
  font-weight: 700;
}
.lightbox-figure {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
}
.lightbox-media {
  max-width: min(1100px, 100%);
  max-height: 100%;
  object-fit: contain;
  border-radius: 0.75rem;
}
.lightbox-video {
  width: min(900px, 100%);
  background: #000;
}
.lightbox-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: 9999px;
  color: #fff;
  background: rgba(255, 255, 255, 0.12);
  transition: background 0.2s ease;
}
.lightbox-btn:hover { background: rgba(255, 255, 255, 0.24); }
.lightbox-btn:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
.lightbox-btn .material-symbols-rounded { font-size: 26px; }
.lightbox-prev,
.lightbox-next {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
}
.lightbox-prev { left: 16px; }
.lightbox-next { right: 16px; }
@media (max-width: 640px) {
  .lightbox-prev,
  .lightbox-next { display: none; } /* มือถือปัดซ้าย/ขวาแทน */
}

.lightbox-enter-active, .lightbox-leave-active { transition: opacity 0.2s ease; }
.lightbox-enter-from, .lightbox-leave-to { opacity: 0; }
</style>
