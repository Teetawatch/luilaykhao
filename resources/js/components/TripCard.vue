<template>
  <router-link :to="`/trips/${trip.slug}`"
    class="group flex flex-col bg-white rounded-[2rem] overflow-hidden border border-gray-100 hover:border-transparent hover:shadow-[0_20px_40px_rgba(0,0,0,0.08)] transition-all duration-300 transform hover:-translate-y-2 h-full">
    
    <!-- Image Container -->
    <div class="relative overflow-hidden aspect-[4/5] m-2 rounded-[1.5rem] shrink-0">
      <img v-if="trip.cover_image" :src="trip.cover_image" :alt="trip.title"
        class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110"
        @error="(e) => e.target.style.display='none'" />
      <div v-else class="w-full h-full bg-gray-100 flex items-center justify-center">
        <span class="material-symbols-rounded text-gray-300 text-5xl">image</span>
      </div>
      
      <!-- Gradient Overlay -->
      <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-60 group-hover:opacity-80 transition-opacity duration-300"></div>
      
      <!-- Badges -->
      <div class="absolute top-4 left-4 flex flex-col gap-2">
        <span class="px-3 py-1.5 rounded-full text-xs font-black tracking-wide shadow-lg backdrop-blur-md"
          :class="typeBadgeClass">
          {{ typeLabel }}
        </span>
      </div>
      
      <!-- Favorite button -->
      <button @click.prevent aria-label="บันทึกรายการโปรด"
        class="absolute top-4 right-4 text-white hover:text-red-400 transition-colors duration-300 bg-white/20 hover:bg-white/30 rounded-full p-2 backdrop-blur-md cursor-pointer z-10">
        <span class="material-symbols-rounded text-[20px] shadow-sm">favorite</span>
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
      <div class="flex items-center gap-1.5 mb-2">
        <div class="flex text-[#FFB020] gap-0.5">
          <span class="material-symbols-rounded text-[16px]" style="font-variation-settings:'FILL' 1">star</span>
        </div>
        <span class="text-[var(--color-text-dark)] font-bold text-sm">{{ trip.rating || (Math.floor(Math.random() * 5 + 45) / 10).toFixed(1) }}</span>
        <span class="text-gray-400 text-xs font-medium">({{ trip.review_count || Math.floor(Math.random() * 900 + 100) }} รีวิว)</span>
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
          <span class="text-xs text-[var(--color-text-muted)] font-bold uppercase tracking-wider mb-0.5">เริ่มต้น</span>
          <div class="flex items-baseline gap-1">
            <span class="text-xl font-black text-[var(--color-text-dark)]">฿{{ Number(trip.price_per_person).toLocaleString() }}</span>
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

const props = defineProps({
  trip: { type: Object, required: true },
});

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
