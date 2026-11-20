<template>
  <div class="font-anuphan bg-[var(--color-sand)] min-h-screen pb-24">
    <PageHero
      image="/images/landscape.webp"
      title="ที่พักชุมชนใกล้ทริป"
      subtitle="โฮมสเตย์และที่พักของคนในพื้นที่ จองคืนก่อนหรือหลังทริปได้ในที่เดียว"
    >
      <template #actions>
        <router-link v-if="auth.isLoggedIn" to="/my-stays" class="hero-btn hero-btn--outline">
          ที่พักที่จองไว้
          <span class="material-symbols-rounded icon-20">arrow_forward</span>
        </router-link>
      </template>
    </PageHero>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
      <div class="filter-bar relative z-10 -mt-7">
        <div class="flex gap-2 overflow-x-auto no-scrollbar">
          <label class="filter-pill" :class="{ 'is-set': filters.province }">
            <span class="material-symbols-rounded filter-pill__icon">map</span>
            <select v-model="filters.province" aria-label="จังหวัด">
              <option value="">ทุกจังหวัด</option>
              <option v-for="p in provinces" :key="p" :value="p">{{ p }}</option>
            </select>
            <span class="material-symbols-rounded filter-pill__chevron">expand_more</span>
          </label>
          <label class="filter-pill" :class="{ 'is-set': filters.type }">
            <span class="material-symbols-rounded filter-pill__icon">cottage</span>
            <select v-model="filters.type" aria-label="ประเภท">
              <option value="">ทุกประเภท</option>
              <option v-for="t in types" :key="t.key" :value="t.key">{{ t.label }}</option>
            </select>
            <span class="material-symbols-rounded filter-pill__chevron">expand_more</span>
          </label>
          <button v-if="filters.province || filters.type || filters.trip" type="button" class="filter-clear press" @click="reset">
            <span class="material-symbols-rounded icon-18">close</span>
            ล้าง
          </button>
        </div>
      </div>

      <!-- ตัวเลขเครือข่ายที่พัก — ไม่บอกยอดเงิน (เจ้าของไม่อยากให้ลูกค้าเห็น) -->
      <div v-if="impact && impact.stays" class="impact mt-6">
        <div class="impact__item">
          <span class="impact__num">{{ impact.stays }}</span>
          <span class="impact__label">ที่พักในเครือข่าย</span>
        </div>
        <div v-if="impact.communities" class="impact__item">
          <span class="impact__num">{{ impact.communities }}</span>
          <span class="impact__label">ชุมชน</span>
        </div>
        <div v-if="impact.nights_hosted" class="impact__item">
          <span class="impact__num">{{ impact.nights_hosted.toLocaleString($dateLocale) }}</span>
          <span class="impact__label">คืนที่ต้อนรับแขก</span>
        </div>
      </div>

      <p v-if="filters.trip && !loading" class="mt-6 text-sm font-bold text-[var(--color-text-mid)]">
        ที่พักใกล้ทริปที่คุณดูอยู่
      </p>

      <div v-if="loading" class="mt-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5" aria-hidden="true">
        <div v-for="i in 6" :key="i" class="rounded-3xl bg-white border border-[var(--color-sand-dark)] overflow-hidden">
          <div class="aspect-[4/3] shimmer"></div>
          <div class="p-5 space-y-3">
            <div class="h-4 w-1/2 rounded shimmer"></div>
            <div class="h-3 w-3/4 rounded shimmer"></div>
          </div>
        </div>
      </div>

      <div v-else-if="!stays.length" class="mt-8 rounded-3xl bg-white border border-[var(--color-sand-dark)] px-6 py-16 text-center">
        <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-[var(--color-accent-tint)] text-[var(--color-accent)]">
          <span class="material-symbols-rounded icon-32">cottage</span>
        </span>
        <p class="mt-4 text-lg font-extrabold text-[var(--color-text-dark)]">ยังไม่มีที่พักตามที่เลือก</p>
        <p class="mt-1 text-sm text-[var(--color-text-muted)]">เรากำลังชวนชุมชนใหม่ ๆ มาร่วม ลองล้างตัวกรองดูก่อนนะครับ</p>
      </div>

      <div v-else v-reveal.stagger class="mt-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <router-link v-for="stay in stays" :key="stay.id" :to="`/stays/${stay.slug}`" class="stay-card group">
          <div class="zoom-media relative aspect-[4/3] bg-[var(--color-primary)]">
            <img v-if="stay.cover_image" :src="stay.cover_image" :alt="stay.name" loading="lazy" class="h-full w-full object-cover" />
            <span v-else class="absolute inset-0 flex items-center justify-center text-white/20">
              <span class="material-symbols-rounded icon-64">cottage</span>
            </span>
            <div class="absolute inset-0 bg-gradient-to-t from-black/55 via-transparent to-transparent"></div>
            <div class="absolute left-3 top-3 flex flex-wrap gap-1.5">
              <span class="stay-chip">{{ stay.type_label }}</span>
            </div>
            <p class="absolute bottom-3 left-4 right-4 flex items-center gap-1 text-[13px] font-bold text-white/90">
              <span class="material-symbols-rounded icon-16">location_on</span>
              {{ [stay.district, stay.province].filter(Boolean).join(' · ') }}
            </p>
          </div>
          <div class="flex flex-1 flex-col p-5">
            <h2 class="text-lg font-extrabold leading-snug text-[var(--color-text-dark)] group-hover:text-[var(--color-accent)] transition-colors">
              {{ stay.name }}
            </h2>
            <p v-if="stay.community_name" class="mt-0.5 text-[13px] font-bold text-[var(--color-accent)]">{{ stay.community_name }}</p>
            <p v-if="stay.distance_note" class="mt-1 text-[13px] font-semibold text-[var(--color-text-mid)]">
              <span class="material-symbols-rounded icon-16 align-[-3px]">near_me</span> {{ stay.distance_note }}
            </p>
            <p v-if="stay.summary" class="mt-1.5 text-[14px] leading-relaxed text-[var(--color-text-mid)] line-clamp-2">{{ stay.summary }}</p>
            <div class="mt-auto flex items-end justify-between pt-4">
              <span v-if="stay.rating_count" class="text-[13px] font-bold text-[var(--color-text-mid)]">
                <span class="material-symbols-rounded icon-16 align-[-3px] text-amber-500">star</span>
                {{ stay.rating_avg.toFixed(1) }} ({{ stay.rating_count }})
              </span>
              <span v-else class="text-[12px] font-bold text-[var(--color-text-muted)]">ที่พักใหม่</span>
              <span v-if="stay.price_from !== null" class="text-right">
                <span class="block text-[11px] font-bold text-[var(--color-text-muted)]">เริ่มต้น/คืน</span>
                <span class="text-lg font-black text-[var(--color-text-dark)]">฿{{ Math.round(stay.price_from).toLocaleString($dateLocale) }}</span>
              </span>
            </div>
          </div>
        </router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '../lib/axios';
import { useAuthStore } from '../stores/auth';
import PageHero from '../components/PageHero.vue';

const route = useRoute();
const auth = useAuthStore();
const loading = ref(true);
const stays = ref([]);
const provinces = ref([]);
const types = ref([]);
const impact = ref(null);
const filters = reactive({ province: '', type: '', trip: String(route.query.trip || '') });

function reset() {
  filters.province = '';
  filters.type = '';
  filters.trip = '';
}

async function load() {
  loading.value = true;
  try {
    const res = await api.get('/stays', {
      params: {
        province: filters.province || undefined,
        type: filters.type || undefined,
        trip: filters.trip || undefined,
      },
    });
    const data = res.data?.data || {};
    stays.value = data.stays || [];
    provinces.value = data.filters?.provinces || [];
    types.value = data.filters?.types || [];
    impact.value = data.impact || null;
  } finally {
    loading.value = false;
  }
}

watch(filters, load);
onMounted(load);
</script>

<style scoped>
.filter-bar { padding: 0.5rem; border-radius: 1.5rem; border: 1px solid var(--color-sand-dark); background-color: #fff; }
.filter-pill { position: relative; display: inline-flex; flex-shrink: 0; align-items: center; border-radius: 999px; background-color: var(--color-sand); }
.filter-pill.is-set { background-color: var(--color-accent); color: #fff; }
.filter-pill select { appearance: none; background: transparent; border: 0; padding: 0.6rem 2.2rem 0.6rem 2.4rem; font-size: 14px; font-weight: 700; color: inherit; cursor: pointer; }
.filter-pill__icon { position: absolute; left: 0.8rem; font-size: 18px; pointer-events: none; }
.filter-pill__chevron { position: absolute; right: 0.7rem; font-size: 18px; pointer-events: none; }
.filter-clear { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0 1rem; border-radius: 999px; font-size: 14px; font-weight: 700; color: var(--color-text-mid); flex-shrink: 0; }
.impact { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 0.75rem; }
.impact__item { padding: 1rem 1.25rem; border-radius: 1.25rem; background: #fff; border: 1px solid var(--color-sand-dark); }
.impact__num { display: block; font-size: 1.5rem; font-weight: 900; color: var(--color-accent); font-variant-numeric: tabular-nums; }
.impact__label { font-size: 13px; font-weight: 700; color: var(--color-text-muted); }
.stay-card { display: flex; flex-direction: column; overflow: hidden; border-radius: 1.5rem; border: 1px solid var(--color-sand-dark); background: #fff; }
.stay-chip { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.25rem 0.65rem; border-radius: 999px; background: rgba(255,255,255,.92); font-size: 12px; font-weight: 800; color: var(--color-text-dark); }
</style>
