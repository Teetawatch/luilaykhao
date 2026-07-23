<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32">
    <div class="max-w-5xl mx-auto px-4 sm:px-6">

      <section class="mb-8">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-2">ฟีดจากนักเดินทาง</h1>
        <p class="text-[#505E5E] text-sm">
          รูปที่คนไปมาแล้วโพสต์เอง ไม่ได้ผ่านการคัดของทีมงาน
        </p>
      </section>

      <div v-if="loading" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div
          v-for="i in 6"
          :key="i"
          class="bg-white rounded-[20px] border border-[#E8EEEF] overflow-hidden animate-pulse"
        >
          <div class="aspect-[4/3] bg-[#EDF1F1]"></div>
          <div class="p-4 space-y-2">
            <div class="h-3 w-1/2 bg-[#EDF1F1] rounded"></div>
            <div class="h-3 w-3/4 bg-[#EDF1F1] rounded"></div>
          </div>
        </div>
      </div>

      <div v-else-if="!posts.length" class="bg-white rounded-[20px] border border-[#E8EEEF] p-8 text-center">
        <span class="material-symbols-rounded text-[44px] text-[#B4C4C4]">photo_library</span>
        <p class="text-[#1a1c1c] font-bold mt-3 mb-1">ยังไม่มีโพสต์ในฟีด</p>
        <p class="text-[#505E5E] text-sm">พอมีคนกลับจากทริปและแชร์รูป จะขึ้นที่นี่</p>
      </div>

      <template v-else>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <article
            v-for="post in posts"
            :key="post.id"
            class="bg-white rounded-[20px] border border-[#E8EEEF] overflow-hidden flex flex-col"
          >
            <button type="button" class="relative block w-full aspect-[4/3] overflow-hidden" @click="open(post)">
              <img
                :src="firstPhoto(post)"
                :alt="post.caption || 'รูปจากนักเดินทาง'"
                loading="lazy"
                class="w-full h-full object-cover"
              />
              <span
                v-if="post.photos.length > 1"
                class="absolute top-3 right-3 bg-black/55 text-white text-[11px] font-bold px-2 py-0.5 rounded-full"
              >{{ post.photos.length }} รูป</span>
            </button>

            <div class="p-4 flex flex-col gap-3 flex-1">
              <div class="flex items-center gap-2.5">
                <img
                  v-if="post.user?.avatar_url"
                  :src="post.user.avatar_url"
                  :alt="post.user?.name || ''"
                  class="w-8 h-8 rounded-full object-cover"
                />
                <span
                  v-else
                  class="w-8 h-8 rounded-full bg-[#E8EEEF] text-[#505E5E] text-[12px] font-bold flex items-center justify-center"
                >{{ (post.user?.name || 'น')[0] }}</span>

                <div class="min-w-0">
                  <p class="font-bold text-[13px] text-[#1a1c1c] truncate">
                    {{ post.user?.name || 'นักเดินทาง' }}
                    <TierBadge :tier="post.user?.tier" :label="post.user?.tier_label" size="sm" class="ml-1" />
                  </p>
                  <p class="text-[11px] text-[#8A9A9A]">{{ timeAgo(post.created_at) }}</p>
                </div>
              </div>

              <router-link
                v-if="post.trip?.slug"
                :to="`/trips/${post.trip.slug}`"
                class="text-[12px] font-bold text-[#006565] truncate"
              >
                {{ post.trip.title }}
              </router-link>

              <p v-if="post.caption" class="text-[13px] text-[#1a1c1c] leading-relaxed line-clamp-3 flex-1">
                {{ post.caption }}
              </p>

              <div class="flex items-center gap-4 pt-1">
                <button
                  type="button"
                  class="flex items-center gap-1.5 text-[12px] font-bold"
                  :class="post.liked_by_me ? 'text-rose-500' : 'text-[#8A9A9A]'"
                  @click="toggleLike(post)"
                >
                  <span class="material-symbols-rounded text-[18px]" :style="post.liked_by_me ? { fontVariationSettings: `'FILL' 1` } : null">favorite</span>
                  {{ post.likes_count }}
                </button>
                <button
                  type="button"
                  class="flex items-center gap-1.5 text-[12px] font-bold text-[#8A9A9A]"
                  @click="open(post)"
                >
                  <span class="material-symbols-rounded text-[18px]">chat_bubble</span>
                  {{ post.comments_count }}
                </button>
              </div>
            </div>
          </article>
        </div>

        <div v-if="hasMore" class="mt-8 text-center">
          <button
            type="button"
            :disabled="loadingMore"
            class="inline-flex items-center gap-2 rounded-[14px] border border-[#E8EEEF] bg-white px-6 py-3 text-sm font-bold text-[#006565] disabled:opacity-50"
            @click="loadMore"
          >
            {{ loadingMore ? 'กำลังโหลด...' : 'ดูเพิ่มเติม' }}
          </button>
        </div>
      </template>
    </div>

    <!-- โพสต์เต็ม + คอมเมนต์ -->
    <Teleport to="body">
      <div
        v-if="active"
        class="fixed inset-0 z-[100] bg-black/85 flex items-start sm:items-center justify-center p-0 sm:p-6 overflow-y-auto"
        @click.self="close"
      >
        <div class="bg-white w-full sm:max-w-2xl sm:rounded-[20px] overflow-hidden my-0 sm:my-6">
          <div class="flex items-center justify-between p-4 border-b border-[#E8EEEF]">
            <p class="font-bold text-[#1a1c1c] text-sm truncate">
              {{ active.user?.name || 'นักเดินทาง' }}
              <span v-if="active.trip?.title" class="text-[#8A9A9A] font-medium"> · {{ active.trip.title }}</span>
            </p>
            <button type="button" class="text-[#8A9A9A] p-1" aria-label="ปิด" @click="close">
              <span class="material-symbols-rounded text-[22px]">close</span>
            </button>
          </div>

          <div class="max-h-[70vh] overflow-y-auto">
            <img
              v-for="(photo, i) in active.photos"
              :key="i"
              :src="photo.url"
              :alt="active.caption || ''"
              class="w-full"
            />

            <div class="p-4 space-y-4">
              <p v-if="active.caption" class="text-sm text-[#1a1c1c] leading-relaxed">{{ active.caption }}</p>

              <div class="flex items-center gap-4">
                <button
                  type="button"
                  class="flex items-center gap-1.5 text-[13px] font-bold"
                  :class="active.liked_by_me ? 'text-rose-500' : 'text-[#8A9A9A]'"
                  @click="toggleLike(active)"
                >
                  <span class="material-symbols-rounded text-[20px]" :style="active.liked_by_me ? { fontVariationSettings: `'FILL' 1` } : null">favorite</span>
                  {{ active.likes_count }}
                </button>
                <span class="text-[13px] font-bold text-[#8A9A9A]">{{ active.comments_count }} ความเห็น</span>
              </div>

              <!-- คอมเมนต์ -->
              <div v-if="commentsLoading" class="py-6 flex justify-center">
                <div class="w-6 h-6 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
              </div>

              <ul v-else-if="comments.length" class="space-y-3">
                <li v-for="comment in comments" :key="comment.id" class="flex gap-2.5">
                  <img
                    v-if="comment.user?.avatar_url"
                    :src="comment.user.avatar_url"
                    :alt="comment.user?.name || ''"
                    class="w-8 h-8 rounded-full object-cover shrink-0"
                  />
                  <span
                    v-else
                    class="w-8 h-8 rounded-full bg-[#E8EEEF] text-[#505E5E] text-[12px] font-bold flex items-center justify-center shrink-0"
                  >{{ (comment.user?.name || 'น')[0] }}</span>
                  <div class="min-w-0">
                    <p class="text-[13px] text-[#1a1c1c]">
                      <span class="font-bold">{{ comment.user?.name || 'นักเดินทาง' }}</span>
                      {{ comment.body }}
                    </p>
                    <p class="text-[11px] text-[#8A9A9A] mt-0.5">{{ timeAgo(comment.created_at) }}</p>
                  </div>
                </li>
              </ul>

              <p v-else class="text-[13px] text-[#8A9A9A]">ยังไม่มีความเห็น</p>
            </div>
          </div>

          <form v-if="isLoggedIn" class="p-4 border-t border-[#E8EEEF] flex gap-2" @submit.prevent="submitComment">
            <input
              v-model.trim="commentBody"
              maxlength="500"
              placeholder="เขียนความเห็น..."
              class="flex-1 min-w-0 rounded-[12px] border border-[#E8EEEF] px-3.5 py-2.5 text-sm"
            />
            <button
              type="submit"
              :disabled="!commentBody || posting"
              class="rounded-[12px] bg-[#006565] text-white px-4 py-2.5 text-[13px] font-bold shrink-0 disabled:opacity-40"
            >
              ส่ง
            </button>
          </form>
          <p v-else class="p-4 border-t border-[#E8EEEF] text-[13px] text-[#8A9A9A] text-center">
            <router-link to="/login" class="font-bold text-[#006565]">เข้าสู่ระบบ</router-link>
            เพื่อกดถูกใจและแสดงความเห็น
          </p>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import api from '../lib/axios';
import TierBadge from '../components/TierBadge.vue';
import { useAuthStore } from '../stores/auth';
import { useToast } from '../lib/toast';

const router = useRouter();
const auth = useAuthStore();
const toast = useToast();

const posts = ref([]);
const page = ref(1);
const lastPage = ref(1);
const loading = ref(true);
const loadingMore = ref(false);

const active = ref(null);
const comments = ref([]);
const commentsLoading = ref(false);
const commentBody = ref('');
const posting = ref(false);

const isLoggedIn = computed(() => auth.isLoggedIn);
const hasMore = computed(() => page.value < lastPage.value);

function firstPhoto(post) {
  return post.photos?.[0]?.url || '';
}

function timeAgo(iso) {
  if (!iso) return '';

  const minutes = Math.round((Date.now() - new Date(iso)) / 60000);
  if (minutes < 1) return 'เมื่อสักครู่';
  if (minutes < 60) return `${minutes} นาทีที่แล้ว`;

  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours} ชม.ที่แล้ว`;

  const days = Math.round(hours / 24);
  if (days < 30) return `${days} วันที่แล้ว`;

  return new Date(iso).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}

async function fetchPage(target) {
  const res = await api.get('/trip-posts', { params: { page: target, per_page: 18 } });
  const body = res.data;

  page.value = body.meta?.current_page ?? target;
  lastPage.value = body.meta?.last_page ?? page.value;

  return body.data || [];
}

async function loadMore() {
  loadingMore.value = true;
  try {
    posts.value.push(...await fetchPage(page.value + 1));
  } finally {
    loadingMore.value = false;
  }
}

async function open(post) {
  active.value = post;
  comments.value = [];
  commentBody.value = '';

  if (!post.comments_count) return;

  commentsLoading.value = true;
  try {
    const res = await api.get(`/trip-posts/${post.id}/comments`);
    comments.value = res.data?.data || [];
  } finally {
    commentsLoading.value = false;
  }
}

function close() {
  active.value = null;
}

function requireLogin() {
  router.push({ name: 'login', query: { redirect: '/feed' } });
}

async function toggleLike(post) {
  if (!isLoggedIn.value) return requireLogin();

  // สลับทันทีให้กดแล้วรู้สึกตอบสนอง แล้วค่อยย้อนกลับถ้า API ไม่ผ่าน
  const wasLiked = post.liked_by_me;
  post.liked_by_me = !wasLiked;
  post.likes_count += wasLiked ? -1 : 1;

  try {
    const res = await api.post(`/trip-posts/${post.id}/like`);
    const data = res.data?.data;
    if (data) {
      post.liked_by_me = data.liked ?? post.liked_by_me;
      post.likes_count = data.likes_count ?? post.likes_count;
    }
  } catch {
    post.liked_by_me = wasLiked;
    post.likes_count += wasLiked ? 1 : -1;
    toast.error('กดถูกใจไม่สำเร็จ');
  }
}

async function submitComment() {
  if (!active.value || !commentBody.value) return;

  posting.value = true;
  try {
    const res = await api.post(`/trip-posts/${active.value.id}/comments`, { body: commentBody.value });
    const comment = res.data?.data;
    if (comment) {
      comments.value.push(comment);
      active.value.comments_count += 1;
    }
    commentBody.value = '';
  } catch (err) {
    toast.error(err.response?.data?.message || 'ส่งความเห็นไม่สำเร็จ');
  } finally {
    posting.value = false;
  }
}

onMounted(async () => {
  try {
    posts.value = await fetchPage(1);
  } finally {
    loading.value = false;
  }
});
</script>
