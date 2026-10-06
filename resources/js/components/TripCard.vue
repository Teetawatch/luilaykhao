<template>
  <!-- การ์ดแบบไม่มีกรอบ: รูปจริงของสถานที่ แล้วตามด้วยข้อมูลเป็นตัวหนังสือเรียบ ๆ
       ไม่มีกล่องขาว ไม่มีป้ายสีบนรูป ไม่มีปุ่มลูกศร — ทั้งการ์ดกดได้อยู่แล้ว
       หน้ารวมมีหลายสิบใบ ถ้าทุกใบตะโกนพร้อมกัน มันอ่านเป็นแคตตาล็อกขายของ -->
  <router-link :to="`/trips/${trip.slug}`" class="group flex flex-col h-full">

    <div class="relative overflow-hidden aspect-[4/5] rounded-2xl bg-[var(--color-sand-dark)] shrink-0">
      <img v-if="trip.thumbnail_image || trip.cover_image" :src="trip.thumbnail_image || trip.cover_image" :alt="trip.title"
        loading="lazy"
        class="w-full h-full object-cover transition-transform duration-500 ease-out group-hover:scale-[1.03]"
        @error="(e) => e.target.style.display='none'" />
      <div v-else class="w-full h-full flex items-center justify-center">
        <span class="material-symbols-rounded text-gray-300 text-5xl">landscape</span>
      </div>

      <!-- ป้ายแคมเปญวันพิเศษ (9.9) — ป้ายเดียวที่อยู่บนรูป เพราะมันบอกราคาที่เปลี่ยนไปจริง
           ไม่ใช่คำโฆษณา และหายไปเองเมื่อแคมเปญจบ -->
      <span v-if="campaign"
        class="absolute bottom-3 left-3 px-2.5 py-1 rounded-md text-[11px] font-bold text-white"
        :style="{ backgroundColor: campaign.theme_color || '#e11d48' }">
        {{ campaign.badge_label ? campaign.badge_label + ' · ' : '' }}{{ campaign.discount_label }}
      </span>

      <button @click.prevent="toggleFav" :aria-label="isFav ? 'นำออกจากรายการโปรด' : 'บันทึกรายการโปรด'"
        class="absolute top-3 right-3 w-9 h-9 flex items-center justify-center rounded-full bg-white cursor-pointer z-10 transition-colors duration-200"
        :class="isFav ? 'text-red-500' : 'text-[var(--color-text-dark)] hover:text-red-500'">
        <span class="material-symbols-rounded text-[19px] leading-none"
          :style="isFav ? 'font-variation-settings:\'FILL\' 1,\'wght\' 400' : 'font-variation-settings:\'FILL\' 0,\'wght\' 500'">favorite</span>
      </button>
    </div>

    <div class="pt-3.5 flex-1 flex flex-col">
      <!-- บรรทัดบน: ประเภท · ปลายทาง ทางซ้าย คะแนนรีวิวทางขวา (ขึ้นเฉพาะเมื่อมีรีวิวจริง) -->
      <div class="flex items-center justify-between gap-3 text-[13px] text-[var(--color-text-muted)] font-medium">
        <span class="truncate">{{ eyebrow }}</span>
        <span v-if="trip.review_count > 0" class="shrink-0 inline-flex items-center gap-0.5 text-[var(--color-text-dark)] font-semibold tabular-nums">
          <span class="material-symbols-rounded text-[15px]" style="font-variation-settings:'FILL' 1">star</span>
          {{ Number(trip.rating).toFixed(1) }}
          <span class="text-[var(--color-text-muted)] font-medium">({{ trip.review_count }})</span>
        </span>
      </div>

      <h3 class="mt-1 text-base font-bold text-[var(--color-text-dark)] leading-snug line-clamp-2 group-hover:underline decoration-1 underline-offset-2">
        {{ trip.title }}
      </h3>

      <!-- ตัวเลขของเส้นทางจริง — ระยะทางกับความสูงสะสมขึ้นเฉพาะทริปที่กรอกไว้ -->
      <p class="mt-1 text-[13px] text-[var(--color-text-muted)] font-medium">
        {{ routeFacts.join(' · ') }}
      </p>

      <!-- รอบถัดไป: บอกว่าไปกันวันไหน ที่นั่งต่อท้ายเฉพาะตอนเหลือน้อยจริง (≤2)
           บางหน้าส่งการ์ดมาโดยไม่มีรอบเดินทางแนบมาด้วย ที่นั่งเหลือน้อยจึงต้องยืนเองได้ -->
      <p v-if="nextDeparture || lastSeats" class="text-[13px] text-[var(--color-text-muted)] font-medium">
        <template v-if="nextDeparture">รอบถัดไป {{ nextDeparture }}</template>
        <span v-if="lastSeats" class="text-amber-700 font-semibold">{{ nextDeparture ? ' · ' : '' }}{{ lastSeats }}</span>
      </p>

      <p class="mt-auto pt-2 flex items-baseline gap-1.5 text-[var(--color-text-dark)]">
        <!-- ราคาก่อนลดขึ้นเฉพาะตอนที่มันต่างจากราคาที่ขายจริง ๆ -->
        <span v-if="hasDiscount" class="text-[13px] text-gray-400 line-through tabular-nums">
          ฿{{ Number(trip.min_original_price).toLocaleString() }}
        </span>
        <span class="text-[15px] font-bold tabular-nums">
          <template v-if="hasPriceRange">
            ฿{{ Number(trip.min_price).toLocaleString() }} – {{ Number(trip.max_price).toLocaleString() }}
          </template>
          <template v-else>฿{{ Number(trip.min_price).toLocaleString() }}</template>
        </span>
        <span class="text-[13px] text-[var(--color-text-muted)] font-medium">/ คน</span>
      </p>
    </div>
  </router-link>
</template>

<script setup>
import { computed } from 'vue';
import { useWishlistStore } from '../stores/wishlist';
import { tripSeatsLeft, tripScarcityLevel } from '../lib/scheduleHelpers';
import { thaiDayMonth } from '../lib/thaiDate';

const props = defineProps({
  trip: { type: Object, required: true },
});

const wishlist = useWishlistStore();
const isFav = computed(() => wishlist.isFavorite(props.trip.id));
function toggleFav() {
  wishlist.toggleFavorite(props.trip);
}

const typeMap = {
  trekking:   'เดินป่า',
  diving:     'ดำน้ำ',
  snorkeling: 'ดำน้ำตื้น',
  climbing:   'รถตู้',
};

const diffMap = { easy: 'ง่าย', medium: 'ปานกลาง', hard: 'ท้าทาย' };

const typeLabel = computed(() => typeMap[props.trip.type] || props.trip.type);
const difficultyLabel = computed(() => diffMap[props.trip.difficulty] || props.trip.difficulty);

// ประเภท · ปลายทาง — ทริปต่างประเทศใช้ชื่อประเทศ ในประเทศใช้สถานที่
const eyebrow = computed(() => [typeLabel.value, props.trip.country_label || props.trip.location]
  .filter(Boolean)
  .join(' · '));

const hasPriceRange = computed(() => Number(props.trip.min_price) !== Number(props.trip.max_price));

// แคมเปญวันพิเศษมาจาก TripResource (คิดจากรอบที่ยังขายได้) ไม่ใช่จากสถานะเว็บ
// ทริปที่ถูกยกเว้นหรือไม่เหลือรอบขาย จึงไม่ติดป้ายทั้งที่แคมเปญเปิดอยู่
const campaign = computed(() => props.trip.campaign || null);

const hasDiscount = computed(() => {
  const before = Number(props.trip.min_original_price || 0);
  return before > Number(props.trip.min_price || 0);
});

// ระยะเวลา/ระดับมีทุกทริป ส่วนระยะทาง/ความสูงสะสมมีเฉพาะทริปที่แอดมินกรอกไว้
const routeFacts = computed(() => {
  const facts = [`${props.trip.duration_days || 1} วัน`];

  if (props.trip.difficulty) facts.push(difficultyLabel.value);
  if (props.trip.is_women_only) facts.push('หญิงล้วน');
  if (Number(props.trip.distance_km) > 0) facts.push(`${Number(props.trip.distance_km).toLocaleString()} กม.`);
  if (Number(props.trip.elevation_gain_m) > 0) facts.push(`ขึ้น ${Number(props.trip.elevation_gain_m).toLocaleString()} ม.`);

  return facts;
});

// วันของรอบที่จะออกเดินทางเร็วที่สุดในบรรดารอบที่ยังเปิดรับ
const nextDeparture = computed(() => {
  const dates = (props.trip.schedules || [])
    .filter((s) => s.status === 'open' && s.departure_date)
    .map((s) => s.departure_date)
    .sort();

  return dates.length ? thaiDayMonth(dates[0]) : null;
});

// ที่นั่งเหลือน้อยจริงเท่านั้น (≤2) — ระดับ "ใกล้เต็ม เหลือ 5 ที่" ไม่ขึ้นบนหน้ารวมแล้ว
const lastSeats = computed(() => {
  if (tripScarcityLevel(props.trip) !== 'last') return null;
  const left = tripSeatsLeft(props.trip);

  return left ? `เหลือ ${left} ที่` : null;
});
</script>

<style scoped>
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
