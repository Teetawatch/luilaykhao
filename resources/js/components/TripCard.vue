<template>
  <router-link :to="`/trips/${trip.slug}`"
    class="group flex flex-col bg-white rounded-[2rem] overflow-hidden border border-gray-100 hover:border-transparent transition-all duration-300 transform hover:-translate-y-2 h-full">
    
    <!-- Image Container -->
    <div class="relative overflow-hidden aspect-[4/5] m-2 rounded-[1.5rem] shrink-0">
      <img v-if="trip.thumbnail_image || trip.cover_image" :src="trip.thumbnail_image || trip.cover_image" :alt="trip.title"
        class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
        @error="(e) => e.target.style.display='none'" />
      <div v-else class="w-full h-full bg-gray-100 flex items-center justify-center">
        <span class="material-symbols-rounded text-gray-300 text-5xl">image</span>
      </div>
      
      <!-- Gradient Overlay -->
      <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-60 group-hover:opacity-80 transition-opacity duration-300"></div>
      
      <!-- Badges -->
      <div class="absolute top-4 left-4 flex flex-col gap-2">
        <span v-if="scarcity" class="px-3 py-1.5 rounded-full text-xs font-black tracking-wide backdrop-blur-md flex items-center gap-1"
          :class="scarcity.level === 'last' ? 'bg-red-500 text-white animate-pulse' : 'bg-amber-400 text-amber-950'">
          <span class="material-symbols-rounded text-[14px]">local_fire_department</span>
          {{ scarcity.label }}
        </span>
        <span class="px-3 py-1.5 rounded-full text-xs font-black tracking-wide backdrop-blur-md"
          :class="typeBadgeClass">
          {{ typeLabel }}
        </span>
        <span v-if="trip.is_women_only" class="px-3 py-1.5 rounded-full text-xs font-black tracking-wide backdrop-blur-md bg-pink-500 text-white flex items-center gap-1">
          <span class="material-symbols-rounded text-[14px]">female</span>
          หญิงล้วน
        </span>
      </div>
      
      <!-- Favorite button -->
      <button @click.prevent="toggleFav" :aria-label="isFav ? 'นำออกจากรายการโปรด' : 'บันทึกรายการโปรด'"
        class="absolute top-4 right-4 w-9 h-9 flex items-center justify-center transition-all duration-300 rounded-full cursor-pointer z-10 backdrop-blur-md"
        :class="isFav ? 'bg-red-500/80 hover:bg-red-600/80 text-white' : 'bg-black/25 hover:bg-black/40 text-white hover:text-red-400'">
        <span class="material-symbols-rounded text-[20px] leading-none"
          :style="isFav ? 'font-variation-settings:\'FILL\' 1,\'wght\' 400' : 'font-variation-settings:\'FILL\' 0,\'wght\' 400'">favorite</span>
      </button>

      <!-- Location / Duration indicator -->
      <div class="absolute bottom-4 left-4 right-4 flex justify-between items-center text-white">
        <div class="flex items-center gap-1.5 bg-black/30 backdrop-blur-md px-3 py-1.5 rounded-full">
          <span class="material-symbols-rounded text-[14px]">schedule</span>
          <span class="text-xs font-bold">{{ trip.duration_days || 1 }} วัน</span>
        </div>
        <div v-if="trip.difficulty" class="flex items-center gap-1.5 bg-black/30 backdrop-blur-md px-3 py-1.5 rounded-full">
          <span class="material-symbols-rounded text-[14px]">terrain</span>
          <span class="text-xs font-bold">{{ difficultyLabel }}</span>
        </div>
      </div>
    </div>

    <!-- Content -->
    <div class="p-5 flex-1 flex flex-col">
      <!-- Rating -->
      <div class="flex items-center justify-between mb-2">
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

      <p v-if="trip.description" class="text-[var(--color-text-muted)] text-sm mb-4 line-clamp-2 font-medium leading-relaxed">
        {{ trip.description }}
      </p>
      <p v-else class="text-[var(--color-text-muted)] text-sm mb-4 flex items-center gap-1.5 font-medium">
        <span class="material-symbols-rounded text-[16px] text-[var(--color-accent)]">location_on</span>
        <span class="truncate">{{ trip.location }}</span>
      </p>

      <!-- Footer -->
      <div class="mt-auto pt-4 flex justify-between items-end border-t border-gray-100">
        <div class="flex flex-col">
          <span class="text-xs text-[var(--color-text-muted)] font-bold uppercase tracking-wider mb-0.5">
            {{ Number(trip.min_price) != Number(trip.max_price) ? 'ช่วงราคา' : 'เริ่มต้น' }}
          </span>
          <div class="flex items-baseline gap-1">
            <template v-if="Number(trip.min_price) != Number(trip.max_price)">
              <span class="text-xl font-black text-[var(--color-text-dark)]">฿{{ Number(trip.min_price).toLocaleString() }} - {{ Number(trip.max_price).toLocaleString() }}</span>
            </template>
            <template v-else>
              <span class="text-xl font-black text-[var(--color-text-dark)]">฿{{ Number(trip.min_price).toLocaleString() }}</span>
            </template>
          </div>
        </div>
        <div class="w-10 h-10 rounded-full bg-[var(--color-sand)] flex items-center justify-center group-hover:bg-[var(--color-accent)] group-hover:text-white transition-colors duration-300">
          <span class="material-symbols-rounded text-[20px] group-hover:translate-x-0.5 transition-transform">arrow_forward</span>
        </div>
      </div>
    </div>
  </router-link>
</template>

<script setup>
import { computed } from 'vue';
import { useWishlistStore } from '../stores/wishlist';
import { tripScarcityLabel, tripScarcityLevel } from '../lib/scheduleHelpers';

const props = defineProps({
  trip: { type: Object, required: true },
});

const scarcity = computed(() => {
  const label = tripScarcityLabel(props.trip);
  return label ? { label, level: tripScarcityLevel(props.trip) } : null;
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
</script>

<style scoped>
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
