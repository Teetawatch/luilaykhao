<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32">
    <div class="max-w-5xl mx-auto px-4 sm:px-6">

      <section class="mb-8">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-2">สถานที่ธรรมชาติในไทย</h1>
        <p class="text-[#505E5E] text-sm max-w-2xl">
          ข้อมูลของแต่ละที่ — ความสูง ระยะเดิน ช่วงที่ควรไป และช่วงที่ปิด
          อ่านได้ไม่ว่าตอนนี้จะมีรอบเปิดหรือไม่
        </p>
      </section>

      <!-- ตัวกรอง -->
      <div class="flex gap-2 overflow-x-auto scrollbar-none mb-6 pb-1">
        <select v-model="filters.region" class="place-filter">
          <option value="">ทุกภูมิภาค</option>
          <option v-for="r in filterOptions.regions" :key="r.key" :value="r.key">{{ r.label }}</option>
        </select>
        <select v-model="filters.type" class="place-filter">
          <option value="">ทุกประเภท</option>
          <option v-for="t in filterOptions.types" :key="t.key" :value="t.key">{{ t.label }}</option>
        </select>
        <select v-model="filters.difficulty" class="place-filter">
          <option value="">ทุกระดับ</option>
          <option v-for="d in filterOptions.difficulties" :key="d.key" :value="d.key">{{ d.label }}</option>
        </select>
        <select v-model.number="filters.month" class="place-filter">
          <option :value="0">ไปเดือนไหนก็ได้</option>
          <option v-for="(name, i) in THAI_MONTHS_LONG" :key="i" :value="i + 1">ไปเดือน{{ name }}</option>
        </select>
        <button v-if="hasFilters" type="button" class="place-filter font-bold text-[#006565] shrink-0" @click="reset">
          ล้าง
        </button>
      </div>

      <div v-if="loading" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div v-for="i in 6" :key="i" class="bg-white rounded-[20px] border border-[#E8EEEF] overflow-hidden animate-pulse">
          <div class="aspect-[3/2] bg-[#EDF1F1]"></div>
          <div class="p-4 space-y-2">
            <div class="h-3 w-1/2 bg-[#EDF1F1] rounded"></div>
            <div class="h-3 w-3/4 bg-[#EDF1F1] rounded"></div>
          </div>
        </div>
      </div>

      <div v-else-if="!places.length" class="bg-white rounded-[20px] border border-[#E8EEEF] p-8 text-center">
        <span class="material-symbols-rounded text-[44px] text-[#B4C4C4]">landscape</span>
        <p class="text-[#1a1c1c] font-bold mt-3 mb-1">ไม่พบสถานที่ตามที่กรอง</p>
        <p class="text-[#505E5E] text-sm">ลองล้างตัวกรองแล้วดูใหม่</p>
      </div>

      <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <router-link
          v-for="place in places"
          :key="place.id"
          :to="`/places/${place.slug}`"
          class="bg-white rounded-[20px] border border-[#E8EEEF] overflow-hidden flex flex-col"
        >
          <div class="aspect-[3/2] bg-[#EDF1F1]">
            <img
              v-if="place.cover_image"
              :src="place.cover_image"
              :alt="place.name"
              loading="lazy"
              class="w-full h-full object-cover"
            />
          </div>

          <div class="p-4 flex flex-col gap-2 flex-1">
            <div>
              <p class="font-extrabold text-[#1a1c1c] text-[15px] leading-snug">{{ place.name }}</p>
              <p class="text-[12px] text-[#8A9A9A] mt-0.5">
                {{ [place.province, place.region_label].filter(Boolean).join(' · ') }}
              </p>
            </div>

            <p v-if="place.summary" class="text-[13px] text-[#505E5E] leading-relaxed line-clamp-2 flex-1">
              {{ place.summary }}
            </p>

            <div class="flex flex-wrap gap-x-3 gap-y-1 text-[12px] text-[#505E5E] pt-1">
              <span v-if="place.elevation_m" class="font-bold">{{ place.elevation_m.toLocaleString('th-TH') }} ม.</span>
              <span v-if="place.trail_distance_km" class="font-bold">{{ place.trail_distance_km }} กม.</span>
              <span v-if="place.difficulty_label" class="font-bold">{{ place.difficulty_label }}</span>
            </div>

            <p v-if="bestMonthsLabel(place)" class="text-[12px] text-[#006565] font-bold">
              ช่วงที่ควรไป: {{ bestMonthsLabel(place) }}
            </p>
          </div>
        </router-link>
      </div>

      <p class="text-center text-[13px] text-[#8A9A9A] mt-8">
        อยากดูว่าเดือนนี้ไปไหนได้บ้าง?
        <router-link to="/seasons" class="font-bold text-[#006565]">ดูปฏิทินฤดูกาล</router-link>
      </p>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, watch, ref } from 'vue';
import api from '../lib/axios';
import { THAI_MONTHS_LONG, THAI_MONTHS_SHORT } from '../lib/thaiDate';

const loading = ref(true);
const places = ref([]);
const filterOptions = ref({ regions: [], types: [], difficulties: [] });

const filters = reactive({ region: '', type: '', difficulty: '', month: 0 });

const hasFilters = computed(
  () => filters.region !== '' || filters.type !== '' || filters.difficulty !== '' || filters.month !== 0,
);

/** ย่อช่วงเดือนที่ควรไปให้อ่านเร็ว — ต่อเนื่องกันแสดงเป็นช่วง ไม่ต่อเนื่องคั่นด้วยจุลภาค */
function bestMonthsLabel(place) {
  const months = [...(place.best_months || [])].sort((a, b) => a - b);
  if (!months.length || months.length === 12) return '';

  const groups = [];
  let start = months[0];
  let prev = months[0];

  months.slice(1).forEach((m) => {
    if (m !== prev + 1) {
      groups.push([start, prev]);
      start = m;
    }
    prev = m;
  });
  groups.push([start, prev]);

  return groups
    .map(([from, to]) => (from === to
      ? THAI_MONTHS_SHORT[from - 1]
      : `${THAI_MONTHS_SHORT[from - 1]}–${THAI_MONTHS_SHORT[to - 1]}`))
    .join(', ');
}

function reset() {
  filters.region = '';
  filters.type = '';
  filters.difficulty = '';
  filters.month = 0;
}

async function load() {
  loading.value = true;
  try {
    const res = await api.get('/places', {
      params: {
        region: filters.region || undefined,
        type: filters.type || undefined,
        difficulty: filters.difficulty || undefined,
        month: filters.month || undefined,
      },
    });
    const data = res.data?.data || {};
    places.value = data.places || [];
    if (data.filters) filterOptions.value = data.filters;
  } finally {
    loading.value = false;
  }
}

watch(filters, load);

onMounted(load);
</script>

<style scoped>
.place-filter {
  flex-shrink: 0;
  border-radius: 12px;
  border: 1px solid #E8EEEF;
  background: #fff;
  padding: 9px 14px;
  font-size: 13px;
  font-weight: 700;
  color: #505E5E;
}
</style>
