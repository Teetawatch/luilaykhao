<template>
  <router-link :to="`/trips/${trip.slug}`"
    class="group flex flex-col bg-white rounded-[2rem] overflow-hidden border border-gray-100 hover:border-[var(--color-accent)]/40 transition-colors duration-300 h-full">

    <!-- รูป — ไม่มีข้อความทับ จึงไม่ต้องมี gradient ดำคลุม ปล่อยให้เห็นสถานที่จริง -->
    <div class="relative overflow-hidden aspect-[4/5] m-2 rounded-[1.5rem] shrink-0">
      <img v-if="trip.thumbnail_image || trip.cover_image" :src="trip.thumbnail_image || trip.cover_image" :alt="trip.title"
        class="w-full h-full object-cover"
        @error="(e) => e.target.style.display='none'" />
      <div v-else class="w-full h-full bg-gray-100 flex items-center justify-center">
        <span class="material-symbols-rounded text-gray-300 text-5xl">image</span>
      </div>

      <!-- ป้ายบนรูปมีได้มากสุดสองอัน: ปลายทางกับประเภทกิจกรรม
           อย่างอื่น (ที่นั่งเหลือ, หญิงล้วน, ระยะเวลา, ระดับ) ย้ายลงไปเป็นข้อมูล
           ในตัวการ์ด — รูปเป็นรูป ไม่ใช่กระดานติดป้าย -->
      <div class="absolute top-4 left-4 flex flex-col gap-2">
        <span v-if="trip.country_label" class="px-3 py-1.5 rounded-full text-xs font-black tracking-wide backdrop-blur-md bg-white text-[var(--color-primary)]">
          {{ trip.country_label }}
        </span>
        <span class="px-3 py-1.5 rounded-full text-xs font-black tracking-wide backdrop-blur-md"
          :class="typeBadgeClass">
          {{ typeLabel }}
        </span>
      </div>

      <!-- Favorite button -->
      <button @click.prevent="toggleFav" :aria-label="isFav ? 'นำออกจากรายการโปรด' : 'บันทึกรายการโปรด'"
        class="absolute top-4 right-4 w-9 h-9 flex items-center justify-center transition-colors duration-300 rounded-full cursor-pointer z-10 backdrop-blur-md"
        :class="isFav ? 'bg-red-500/80 hover:bg-red-600/80 text-white' : 'bg-black/25 hover:bg-black/40 text-white hover:text-red-400'">
        <span class="material-symbols-rounded text-[20px] leading-none"
          :style="isFav ? 'font-variation-settings:\'FILL\' 1,\'wght\' 400' : 'font-variation-settings:\'FILL\' 0,\'wght\' 400'">favorite</span>
      </button>
    </div>

    <!-- Content -->
    <div class="p-5 flex-1 flex flex-col">
      <!-- Rating -->
      <div class="flex items-center justify-between gap-2 mb-2">
        <div class="flex items-center gap-1.5">
          <div class="flex text-[#FFB020] gap-0.5">
            <span class="material-symbols-rounded text-[16px]" style="font-variation-settings:'FILL' 1">star</span>
          </div>
          <template v-if="trip.review_count > 0">
            <span class="text-[var(--color-text-dark)] font-bold text-sm">{{ Number(trip.rating).toFixed(1) }}</span>
            <span class="text-gray-400 text-xs font-medium">({{ trip.review_count }} รีวิว)</span>
          </template>
          <template v-else>
            <span class="text-gray-400 text-xs font-medium">ยังไม่มีรีวิว</span>
          </template>
        </div>
        <div v-if="trip.confirmed_passengers_count > 0" class="flex items-center gap-1 text-[var(--color-accent)] font-bold text-xs bg-[var(--color-accent-light)]/10 px-2 py-1 rounded-full">
          <span class="material-symbols-rounded text-[16px]">group</span>
          <span>{{ trip.confirmed_passengers_count }} คนร่วมทริป</span>
        </div>
      </div>

      <h3 class="text-[1.1rem] font-extrabold text-[var(--color-text-dark)] mb-2 group-hover:text-[var(--color-accent)] transition-colors duration-300 leading-snug line-clamp-2">
        {{ trip.title }}
      </h3>

      <p v-if="trip.description" class="text-[var(--color-text-muted)] text-sm mb-3 line-clamp-2 font-medium leading-relaxed">
        {{ trip.description }}
      </p>
      <p v-else class="text-[var(--color-text-muted)] text-sm mb-3 flex items-center gap-1.5 font-medium">
        <span class="material-symbols-rounded text-[16px] text-[var(--color-accent)]">location_on</span>
        <span class="truncate">{{ trip.location }}</span>
      </p>

      <!-- ตัวเลขของเส้นทางจริง — ระยะทางกับความสูงสะสมโผล่เฉพาะทริปที่กรอกไว้จริง
           ไม่มีก็เหลือแค่ระยะเวลากับระดับ ไม่เติมคำโฆษณาแทนช่องว่าง -->
      <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs font-bold text-[var(--color-text-muted)] mb-3">
        <span v-for="fact in routeFacts" :key="fact.key" class="inline-flex items-center gap-1">
          <span class="material-symbols-rounded text-[15px] text-[var(--color-accent)]">{{ fact.icon }}</span>
          {{ fact.label }}
        </span>
      </div>

      <!-- รอบถัดไป: บอกว่าไปกันวันไหน แทนที่จะบอกว่าเหลือน้อยแล้ว
           จำนวนที่นั่งขึ้นต่อท้ายเฉพาะตอนเหลือน้อยจริง (≤2) และเป็นตัวหนังสือเฉย ๆ
           ไม่กะพริบ ไม่มีไอคอนไฟ — ทั้งหน้ามีการ์ดหลายสิบใบ ถ้าทุกใบเร่งพร้อมกัน
           มันไม่ได้แปลว่าด่วน มันแปลว่าเว็บกำลังตะโกน -->
      <p v-if="nextDeparture || lastSeats" class="text-xs font-bold text-[var(--color-text-dark)] mb-3 flex items-center gap-1.5">
        <span class="material-symbols-rounded text-[15px] text-[var(--color-accent)]">event</span>
        <!-- บางหน้าส่งการ์ดมาโดยไม่มีรอบเดินทางแนบมาด้วย ที่นั่งเหลือน้อยจึงต้องยืนเองได้ -->
        <template v-if="nextDeparture">รอบถัดไป {{ nextDeparture }}</template>
        <span v-if="lastSeats" class="text-amber-700 font-bold">{{ nextDeparture ? '· ' : '' }}{{ lastSeats }}</span>
      </p>

      <!-- Footer -->
      <div class="mt-auto pt-4 flex justify-between items-end border-t border-gray-100">
        <div class="flex flex-col">
          <span class="text-xs text-[var(--color-text-muted)] font-bold mb-0.5">
            {{ hasPriceRange ? 'ช่วงราคาต่อคน' : 'ราคาต่อคน' }}
          </span>
          <div class="flex items-baseline gap-1">
            <span class="text-base font-extrabold text-[var(--color-text-dark)] tabular-nums">
              <template v-if="hasPriceRange">
                ฿{{ Number(trip.min_price).toLocaleString() }} - {{ Number(trip.max_price).toLocaleString() }}
              </template>
              <template v-else>฿{{ Number(trip.min_price).toLocaleString() }}</template>
            </span>
          </div>
        </div>
        <div class="w-10 h-10 rounded-full bg-[var(--color-sand)] flex items-center justify-center group-hover:bg-[var(--color-accent)] group-hover:text-white transition-colors duration-300">
          <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
        </div>
      </div>
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

/* Category color mapping per design spec */
const typeMap = {
  trekking:  { label: 'เดินป่า',    class: 'bg-[#2D7A4F] text-white' },
  diving:    { label: 'ดำน้ำ',      class: 'bg-[#1A5F8A] text-white' },
  snorkeling:{ label: 'ดำน้ำตื้น', class: 'bg-[#3B9DD4] text-white' },
  climbing:  { label: 'รถตู้',      class: 'bg-[#C8963E] text-white' },
};

const diffMap = { easy: 'ง่าย', medium: 'ปานกลาง', hard: 'ท้าทาย' };

const typeLabel = computed(() => typeMap[props.trip.type]?.label || props.trip.type);
const typeBadgeClass = computed(() => typeMap[props.trip.type]?.class || 'bg-[#6B8F7A] text-white');
const difficultyLabel = computed(() => diffMap[props.trip.difficulty] || props.trip.difficulty);

const hasPriceRange = computed(() => Number(props.trip.min_price) !== Number(props.trip.max_price));

// ระยะเวลา/ระดับมีทุกทริป ส่วนระยะทาง/ความสูงสะสมมีเฉพาะทริปที่แอดมินกรอกไว้
const routeFacts = computed(() => {
  const facts = [
    { key: 'days', icon: 'schedule', label: `${props.trip.duration_days || 1} วัน` },
  ];

  if (props.trip.is_women_only) {
    facts.push({ key: 'women', icon: 'female', label: 'หญิงล้วน' });
  }
  if (props.trip.difficulty) {
    facts.push({ key: 'difficulty', icon: 'terrain', label: difficultyLabel.value });
  }
  if (Number(props.trip.distance_km) > 0) {
    facts.push({ key: 'distance', icon: 'straighten', label: `${Number(props.trip.distance_km).toLocaleString()} กม.` });
  }
  if (Number(props.trip.elevation_gain_m) > 0) {
    facts.push({ key: 'elevation', icon: 'landscape', label: `+${Number(props.trip.elevation_gain_m).toLocaleString()} ม.` });
  }

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
