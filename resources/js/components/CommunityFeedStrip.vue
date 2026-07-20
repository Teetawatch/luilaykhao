<template>
  <section v-if="photos.length" class="bg-[var(--color-sand)] py-12 md:py-16">
    <div class="max-w-7xl mx-auto px-6 md:px-8">
      <div class="flex items-end justify-between gap-6 mb-6">
        <div>
          <div class="flex items-center gap-2 mb-1.5">
            <span class="relative flex h-2 w-2">
              <span class="absolute inline-flex h-full w-full rounded-full bg-[var(--color-accent)] opacity-60"></span>
              <span class="relative inline-flex h-2 w-2 rounded-full bg-[var(--color-accent)]"></span>
            </span>
            <span class="text-[13px] font-bold tracking-wide text-[var(--color-text-muted)]">จากทริปที่เพิ่งกลับมา</span>
          </div>
          <h2 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] tracking-tight">
            รูปจริงจากคนที่ไปมาแล้ว
          </h2>
        </div>
        <router-link
          to="/gallery"
          class="hidden sm:inline-flex items-center gap-1 text-sm font-bold text-[var(--color-primary)] hover:text-[var(--color-accent)] transition-colors shrink-0"
        >
          ดูทั้งหมด
          <span class="material-symbols-rounded text-[18px]">arrow_forward</span>
        </router-link>
      </div>

      <!-- แถวรูปแนวนอน — ให้ "รูป" เป็นพระเอก ไม่ใส่ effect ทับ -->
      <div class="flex gap-3 md:gap-4 overflow-x-auto pb-3 snap-x feed-strip">
        <router-link
          v-for="photo in photos"
          :key="photo.key"
          :to="photo.tripSlug ? `/trips/${photo.tripSlug}` : '/trips'"
          class="group relative shrink-0 snap-start w-44 md:w-56 aspect-[4/5] rounded-2xl overflow-hidden bg-black/5"
        >
          <img
            :src="photo.url"
            :alt="photo.caption || photo.tripTitle || 'รูปจากทริป'"
            loading="lazy"
            class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-[1.03]"
          />
          <!-- แถบล่างพอให้อ่านชื่อออก ไม่คลุมทั้งรูป -->
          <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-3 pt-8">
            <div class="text-white text-[12px] font-bold leading-tight line-clamp-1">
              {{ photo.tripTitle }}
            </div>
            <div class="text-white/75 text-[11px] leading-tight mt-0.5 line-clamp-1">
              {{ photo.userName }} · {{ photo.timeAgo }}
            </div>
          </div>
        </router-link>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../lib/axios';

/** จำนวนรูปสูงสุดบนแถบหน้าแรก — พอให้เลื่อนได้ ไม่ถ่วงการโหลด */
const MAX_PHOTOS = 18;

const photos = ref([]);

function timeAgo(value) {
  if (!value) return '';
  const diffMs = Date.now() - new Date(value).getTime();
  const mins = Math.floor(diffMs / 60000);
  if (mins < 60) return mins <= 1 ? 'เมื่อสักครู่' : `${mins} นาทีที่แล้ว`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return `${hours} ชม.ที่แล้ว`;
  const days = Math.floor(hours / 24);
  if (days < 7) return `${days} วันที่แล้ว`;
  const weeks = Math.floor(days / 7);
  if (weeks < 5) return `${weeks} สัปดาห์ที่แล้ว`;
  return `${Math.floor(days / 30)} เดือนที่แล้ว`;
}

onMounted(async () => {
  try {
    const res = await api.get('/trip-posts', { params: { per_page: 12 } });
    const posts = res.data?.data || [];

    // แผ่โพสต์ออกเป็นรูปเดี่ยว ๆ เพื่อให้แถบดูแน่นเหมือนฟีดจริง
    photos.value = posts
      .flatMap((post) =>
        (post.photos || []).map((p, i) => ({
          key: `${post.id}-${i}`,
          url: p.url,
          caption: post.caption,
          tripSlug: post.trip?.slug,
          tripTitle: post.trip?.title || 'ทริปลุยเลเขา',
          userName: post.user?.name || 'นักเดินทาง',
          timeAgo: timeAgo(post.created_at),
        }))
      )
      .filter((p) => p.url)
      .slice(0, MAX_PHOTOS);
  } catch {
    photos.value = [];
  }
});
</script>

<style scoped>
.feed-strip {
  scrollbar-width: none;
}
.feed-strip::-webkit-scrollbar {
  display: none;
}
</style>
