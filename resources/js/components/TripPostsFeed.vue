<template>
  <!-- ฟีดรูปหลังทริป (UGC) — โพสต์จริงจากลูกค้าที่เคยเดินทาง อ่านอย่างเดียวบนเว็บ -->
  <section v-if="posts.length > 0" class="mt-16 pt-16 border-t border-gray-200">
    <div class="mb-10">
      <h3 class="text-2xl md:text-4xl font-extrabold text-[var(--color-text-dark)] tracking-tight mb-2">
        ฟีดจากนักเดินทาง
      </h3>
      <p class="text-[var(--color-text-muted)] font-semibold">
        รูปจริงจากลูกค้าที่เพิ่งกลับจากทริปนี้ · {{ total }} โพสต์
      </p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
      <article
        v-for="post in posts"
        :key="post.id"
        class="bg-white rounded-[1.5rem] border border-gray-100 overflow-hidden flex flex-col"
      >
        <!-- รูป (คลิกเปิดดูเต็ม) -->
        <button
          type="button"
          class="relative block w-full aspect-[4/3] overflow-hidden group"
          @click="openLightbox(post)"
        >
          <img
            :src="firstPhoto(post)"
            :alt="post.caption || 'รูปจากนักเดินทาง'"
            loading="lazy"
            class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
          />
          <span
            v-if="post.photos.length > 1"
            class="absolute top-3 right-3 bg-black/55 text-white text-xs font-bold px-2.5 py-1 rounded-full flex items-center gap-1"
          >
            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
              <path d="M4 3a2 2 0 00-2 2v8a2 2 0 002 2h1V7a3 3 0 013-3h8V5a2 2 0 00-2-2H4z" />
              <path d="M8 6a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2V8a2 2 0 00-2-2H8z" />
            </svg>
            {{ post.photos.length }}
          </span>
        </button>

        <div class="p-5 flex flex-col gap-3 flex-1">
          <!-- ผู้โพสต์ -->
          <div class="flex items-center gap-3">
            <img
              v-if="post.user?.avatar_url"
              :src="post.user.avatar_url"
              :alt="post.user?.name || ''"
              class="w-9 h-9 rounded-full object-cover"
            />
            <div
              v-else
              class="w-9 h-9 rounded-full bg-[var(--color-primary)]/10 flex items-center justify-center text-[var(--color-primary)] font-extrabold"
            >
              {{ (post.user?.name || 'น')[0] }}
            </div>
            <div class="min-w-0">
              <p class="font-extrabold text-sm text-[var(--color-text-dark)] truncate">
                {{ post.user?.name || 'นักเดินทาง' }}
                <TierBadge :tier="post.user?.tier" :label="post.user?.tier_label" size="sm" class="ml-1" />
              </p>
              <p class="text-xs text-[var(--color-text-muted)] font-semibold">
                {{ timeAgo(post.created_at) }}
              </p>
            </div>
          </div>

          <p
            v-if="post.caption"
            class="text-sm text-[var(--color-text-dark)] leading-relaxed line-clamp-3 flex-1"
          >
            {{ post.caption }}
          </p>

          <!-- ยอดไลก์/คอมเมนต์ (อ่านอย่างเดียว — กดไลก์/คอมเมนต์ได้ในแอป) -->
          <div class="flex items-center gap-4 text-xs font-bold text-[var(--color-text-muted)] pt-1">
            <span class="flex items-center gap-1.5">
              <svg class="w-4 h-4 text-rose-500" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z" clip-rule="evenodd" />
              </svg>
              {{ post.likes_count }}
            </span>
            <span class="flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10c0 3.866-3.582 7-8 7a8.841 8.841 0 01-4.083-.98L2 17l1.338-3.123C2.493 12.767 2 11.434 2 10c0-3.866 3.582-7 8-7s8 3.134 8 7z" clip-rule="evenodd" />
              </svg>
              {{ post.comments_count }}
            </span>
          </div>
        </div>
      </article>
    </div>

    <div v-if="hasMore" class="mt-10 text-center">
      <button
        type="button"
        :disabled="loadingMore"
        class="inline-flex items-center gap-2 px-8 py-3 rounded-full border-2 border-[var(--color-primary)] text-[var(--color-primary)] font-extrabold hover:bg-[var(--color-primary)] hover:text-white transition-colors disabled:opacity-50"
        @click="loadMore"
      >
        <span
          v-if="loadingMore"
          class="w-5 h-5 border-2 border-current border-t-transparent rounded-full animate-spin"
        ></span>
        {{ loadingMore ? 'กำลังโหลด...' : 'ดูโพสต์เพิ่มเติม' }}
      </button>
    </div>

    <!-- Lightbox แบบเบา ๆ: เลื่อนดูรูปทั้งหมดของโพสต์ -->
    <Teleport to="body">
      <div
        v-if="lightboxPost"
        class="fixed inset-0 z-[100] bg-black/90 flex items-center justify-center p-4"
        @click.self="lightboxPost = null"
      >
        <button
          type="button"
          class="absolute top-4 right-4 text-white/80 hover:text-white p-2"
          aria-label="ปิด"
          @click="lightboxPost = null"
        >
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
        <div class="max-w-3xl w-full max-h-[85vh] overflow-y-auto space-y-4" @click.stop>
          <img
            v-for="(photo, i) in lightboxPost.photos"
            :key="i"
            :src="photo.url"
            :alt="lightboxPost.caption || ''"
            class="w-full rounded-2xl"
          />
          <p v-if="lightboxPost.caption" class="text-white/90 text-sm leading-relaxed pb-4">
            {{ lightboxPost.caption }}
          </p>
        </div>
      </div>
    </Teleport>
  </section>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue';
import api from '../lib/axios';
import TierBadge from './TierBadge.vue';

const props = defineProps({
  slug: { type: String, required: true },
});

const posts = ref([]);
const total = ref(0);
const page = ref(1);
const lastPage = ref(1);
const loadingMore = ref(false);
const lightboxPost = ref(null);

const hasMore = computed(() => page.value < lastPage.value);

function firstPhoto(post) {
  return post.photos?.[0]?.url || '';
}

function timeAgo(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  const diffMs = Date.now() - date.getTime();
  const mins = Math.floor(diffMs / 60000);
  if (mins < 1) return 'เมื่อกี้';
  if (mins < 60) return `${mins} นาทีที่แล้ว`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `${hours} ชม.ที่แล้ว`;
  const days = Math.floor(hours / 24);
  if (days < 7) return `${days} วันที่แล้ว`;
  return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

function openLightbox(post) {
  lightboxPost.value = post;
}

async function fetchPage(target) {
  const res = await api.get(`/trips/${props.slug}/posts`, { params: { page: target } });
  const data = res.data?.data || [];
  const meta = res.data?.meta || {};
  page.value = Number(meta.current_page || target);
  lastPage.value = Number(meta.last_page || 1);
  total.value = Number(meta.total || data.length);
  return data;
}

async function loadMore() {
  if (loadingMore.value) return;
  loadingMore.value = true;
  try {
    const data = await fetchPage(page.value + 1);
    posts.value = [...posts.value, ...data];
  } catch {
    // โหลดเพิ่มไม่ได้ — คงรายการเดิมไว้
  } finally {
    loadingMore.value = false;
  }
}

onMounted(async () => {
  try {
    posts.value = await fetchPage(1);
  } catch {
    // ส่วนเสริมของหน้า — โหลดไม่ได้ก็ไม่แสดง section
  }
});
</script>
