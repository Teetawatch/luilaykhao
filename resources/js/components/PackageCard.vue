<template>
  <!-- การ์ดทริปของทั้งเว็บ (หน้าแรก ทริปทั้งหมด ทริปใกล้เคียง ผลค้นหาทริปที่ใช่) — ภาพ 3:2 ป้ายจำนวนวันบนภาพ ราคาเริ่มต้นชัด ๆ ด้านล่าง
       ส่วนลดขึ้นเฉพาะตอนราคาก่อนลดสูงกว่าราคาขายจริง ไม่มีป้าย "ขายดี" ที่ระบบไม่ได้คำนวณ -->
  <router-link :to="`/trips/${trip.slug}`" class="pkg group">
    <div class="pkg-media zoom-media">
      <img
        v-if="image"
        :src="image"
        :alt="trip.title"
        loading="lazy"
        class="w-full h-full object-cover"
        @error="(e) => (e.target.style.display = 'none')"
      />
      <span v-else class="absolute inset-0 flex items-center justify-center text-gray-300">
        <span class="material-symbols-rounded icon-48">landscape</span>
      </span>

      <span v-if="discountPct" class="pkg-badge">{{ $t('ลด {discountPct}%', { discountPct }) }}</span>
      <span
        v-else-if="trip.campaign"
        class="pkg-badge"
        :style="{ backgroundColor: trip.campaign.theme_color || '#e11d48' }"
      >{{ trip.campaign.badge_label || trip.campaign.discount_label }}</span>

      <span class="pkg-duration">
        <span class="material-symbols-rounded icon-16">schedule</span>
        {{ durationLabel }}
      </span>

      <button
        type="button"
        class="pkg-fav"
        :class="isFav ? 'text-red-500' : 'text-[var(--color-text-dark)]'"
        :aria-label="isFav ? $t('นำออกจากรายการโปรด') : $t('บันทึกรายการโปรด')"
        @click.prevent="wishlist.toggleFavorite(trip)"
      >
        <span
          class="material-symbols-rounded icon-20"
          :style="isFav ? `font-variation-settings:'FILL' 1` : ''"
        >favorite</span>
      </button>
    </div>

    <div class="flex flex-1 flex-col p-4 md:p-5">
      <p class="flex items-center gap-1 text-[13px] font-semibold text-[var(--color-accent)]">
        <span class="material-symbols-rounded icon-16">location_on</span>
        <span class="truncate">{{ trip.country_label || trip.location || typeLabel }}</span>
      </p>
      <h3 class="pkg-title mt-1.5 text-[17px] font-bold leading-snug text-[var(--color-text-dark)]">
        {{ trip.title }}
      </h3>
      <p v-if="facts.length" class="mt-1.5 text-[13px] text-[var(--color-text-muted)]">{{ facts.join(' · ') }}</p>

      <div class="mt-auto pt-4">
        <div class="flex flex-wrap items-end justify-between gap-x-3 gap-y-1 border-t border-[var(--color-sand-dark)] pt-3">
          <div class="min-w-0">
            <p class="text-[12px] font-medium text-[var(--color-text-muted)]">
              {{ $t('ราคาเริ่มต้น') }}
              <s v-if="discountPct" class="ml-1">{{ $t('฿{fmt}', { fmt: fmt(trip.min_original_price) }) }}</s>
            </p>
            <p class="whitespace-nowrap text-[22px] font-extrabold leading-tight text-[var(--color-text-dark)] tabular-nums">
              {{ $t('฿{fmt}', { fmt: fmt(trip.min_price) }) }}<span class="ml-1 text-[13px] font-semibold text-[var(--color-text-muted)]">{{ $t('/ ท่าน') }}</span>
            </p>
          </div>
          <div class="shrink-0 ml-auto text-right text-[12.5px] leading-tight">
            <p v-if="trip.review_count > 0" class="inline-flex items-center gap-0.5 font-bold text-[var(--color-text-dark)]">
              <span class="material-symbols-rounded icon-16 text-[#F5A524]" style="font-variation-settings:'FILL' 1">star</span>
              {{ Number(trip.rating).toFixed(1) }}
              <span class="font-medium text-[var(--color-text-muted)]">({{ trip.review_count }})</span>
            </p>
            <p v-if="nextDeparture" class="mt-0.5 text-[var(--color-text-muted)]">{{ $t('รอบถัดไป {nextDeparture}', { nextDeparture }) }}</p>
            <p v-if="lastSeats" class="mt-0.5 font-semibold text-amber-700">{{ lastSeats }}</p>
          </div>
        </div>
      </div>
    </div>
  </router-link>
</template>

<script setup>
import { $t } from '@/i18n';
import { DATE_LOCALE } from '@/i18n';
import { computed } from 'vue';
import { useWishlistStore } from '../stores/wishlist';
import { tripCardImage } from '../lib/tripImage';
import { thaiDayMonth } from '../lib/thaiDate';
import { bangkokToday } from '../lib/bangkokDate';
import { tripScarcityLevel, tripSeatsLeft } from '../lib/scheduleHelpers';

const props = defineProps({
  trip: { type: Object, required: true },
});

const wishlist = useWishlistStore();

const TYPE_LABELS = { trekking: $t('เดินป่า'), snorkeling: $t('ดำน้ำตื้น'), diving: $t('ดำน้ำ') };
const DIFFICULTY_LABELS = { easy: $t('ระดับง่าย'), medium: $t('ระดับปานกลาง'), hard: $t('ระดับท้าทาย') };

const image = computed(() => tripCardImage(props.trip));
const isFav = computed(() => wishlist.isFavorite(props.trip.id));
const typeLabel = computed(() => TYPE_LABELS[props.trip.type] || props.trip.type);

const fmt = (n) => Number(n || 0).toLocaleString(DATE_LOCALE);

const durationLabel = computed(() => {
  const d = Number(props.trip.duration_days) || 1;
  return d > 1 ? $t('{d} วัน {v} คืน', { d, v: d - 1 }) : $t('1 วัน');
});

const discountPct = computed(() => {
  const before = Number(props.trip.min_original_price || 0);
  const now = Number(props.trip.min_price || 0);
  if (!before || before <= now) return 0;
  const pct = Math.round(((before - now) / before) * 100);
  return pct >= 5 ? pct : 0;
});

// ตัวเลขที่ทริปกรอกไว้จริง — ระดับ ระยะทาง ความสูงสะสม
const facts = computed(() => {
  const t = props.trip;
  const out = [];
  if (DIFFICULTY_LABELS[t.difficulty]) out.push(DIFFICULTY_LABELS[t.difficulty]);
  if (Number(t.distance_km) > 0) out.push($t('เดิน {Number} กม.', { Number: Number(t.distance_km).toLocaleString() }));
  if (Number(t.elevation_gain_m) > 0) out.push($t('ขึ้น {Number} ม.', { Number: Number(t.elevation_gain_m).toLocaleString() }));
  return out;
});

// ที่นั่งเหลือน้อยจริงเท่านั้น (≤2) — ตัวหนังสือเรียบ ๆ ไม่กะพริบ เหมือนการ์ดเดิม
const lastSeats = computed(() => {
  if (tripScarcityLevel(props.trip) !== 'last') return null;
  const left = tripSeatsLeft(props.trip);
  return left ? $t('เหลือ {left} ที่', { left }) : null;
});

const nextDeparture = computed(() => {
  const today = bangkokToday();
  const dates = (props.trip.schedules || [])
    .filter((s) => s.status === 'open' && s.departure_date && s.departure_date >= today)
    .map((s) => s.departure_date)
    .sort();
  return dates.length ? thaiDayMonth(dates[0]) : null;
});
</script>

<style scoped>
.pkg {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
  border-radius: 0.875rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  transition: border-color 0.25s ease, transform 0.25s ease;
}
.pkg:hover {
  border-color: color-mix(in srgb, var(--color-accent) 40%, transparent);
  transform: translateY(-3px);
}
.pkg:hover .pkg-title {
  color: var(--color-accent);
}
.pkg-media {
  position: relative;
  aspect-ratio: 3 / 2;
  background-color: var(--color-sand-dark);
  overflow: hidden;
}
.pkg-title {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  transition: color 0.2s ease;
}
.pkg-badge {
  position: absolute;
  top: 0.75rem;
  left: 0.75rem;
  padding: 0.2rem 0.6rem;
  border-radius: 0.375rem;
  background-color: #dc2626;
  color: #fff;
  font-size: 12.5px;
  font-weight: 800;
}
.pkg-duration {
  position: absolute;
  left: 0.75rem;
  bottom: 0.75rem;
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.25rem 0.65rem 0.25rem 0.5rem;
  border-radius: 9999px;
  background-color: rgba(255, 255, 255, 0.95);
  color: var(--color-text-dark);
  font-size: 12.5px;
  font-weight: 700;
}
.pkg-fav {
  position: absolute;
  top: 0.625rem;
  right: 0.625rem;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 9999px;
  background-color: #fff;
  transition: color 0.2s ease;
}
.pkg-fav:hover {
  color: #ef4444;
}
</style>
