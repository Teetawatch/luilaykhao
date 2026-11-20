<template>
  <section v-if="photos.length" class="bg-[var(--color-sand)] py-12 md:py-16">
    <div class="max-w-7xl mx-auto px-6 md:px-8">
      <SectionTitle
        class="mb-8 md:mb-10"
        :title="$t('รูปจริงจากคนที่ไปมาแล้ว')"
        :subtitle="$t('จากทริปที่เพิ่งกลับมา ผู้ร่วมทริปถ่ายและโพสต์เอง')"
      >
        <template #action>
          <router-link to="/gallery" class="section-more">
            {{ $t('ดูทั้งหมด') }}
            <span class="material-symbols-rounded icon-18">chevron_right</span>
          </router-link>
        </template>
      </SectionTitle>

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
            :alt="photo.caption || photo.tripTitle || $t('รูปจากทริป')"
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
import { $t } from '@/i18n';
import { ref, onMounted } from 'vue';
import api from '../lib/axios';
import SectionTitle from './SectionTitle.vue';

/** จำนวนรูปสูงสุดบนแถบหน้าแรก — พอให้เลื่อนได้ ไม่ถ่วงการโหลด */
const MAX_PHOTOS = 18;

const photos = ref([]);

function timeAgo(value) {
  if (!value) return '';
  const diffMs = Date.now() - new Date(value).getTime();
  const mins = Math.floor(diffMs / 60000);
  if (mins < 60) return mins <= 1 ? $t('เมื่อสักครู่') : $t('{mins} นาทีที่แล้ว', { mins });
  const hours = Math.floor(mins / 60);
  if (hours < 24) return $t('{hours} ชม.ที่แล้ว', { hours });
  const days = Math.floor(hours / 24);
  if (days < 7) return $t('{days} วันที่แล้ว', { days });
  const weeks = Math.floor(days / 7);
  if (weeks < 5) return $t('{weeks} สัปดาห์ที่แล้ว', { weeks });
  return $t('{floor} เดือนที่แล้ว', { floor: Math.floor(days / 30) });
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
          tripTitle: post.trip?.title || $t('ทริปลุยเลเขา'),
          userName: post.user?.name || $t('นักเดินทาง'),
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
