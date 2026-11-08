<template>
  <div class="font-anuphan bg-[var(--color-sand)] min-h-screen pb-24">

    <div v-if="loading" aria-hidden="true">
      <div class="h-[52vh] min-h-[380px] shimmer"></div>
      <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8 -mt-12 relative">
        <div class="h-28 rounded-3xl bg-white border border-[var(--color-sand-dark)]"></div>
      </div>
    </div>

    <div v-else-if="notFound" class="max-w-xl mx-auto px-4 pt-24 text-center">
      <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-[var(--color-accent-tint)] text-[var(--color-accent)]">
        <span class="material-symbols-rounded icon-32">explore_off</span>
      </span>
      <p class="mt-4 text-xl font-extrabold text-[var(--color-text-dark)]">ไม่พบสถานที่นี้</p>
      <router-link to="/places" class="mt-6 inline-flex rounded-full bg-[var(--color-accent)] px-6 py-3 text-sm font-bold text-white press">
        ดูสถานที่ทั้งหมด
      </router-link>
    </div>

    <template v-else-if="place">
      <!-- ── ภาพปก ── -->
      <section class="place-hero relative flex h-[52vh] min-h-[380px] flex-col overflow-hidden bg-[var(--color-primary)] md:h-[60vh]">
        <img
          v-if="place.cover_image"
          v-parallax="0.25"
          :src="place.cover_image"
          :alt="place.name"
          fetchpriority="high"
          class="place-hero__img absolute inset-x-0 -top-[10%] h-[110%] w-full object-cover"
        />
        <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/25 to-black/40"></div>

        <nav aria-label="เส้นทางนำทาง" class="relative z-10 mx-auto flex w-full max-w-6xl items-center gap-1 px-4 pt-6 text-[13px] font-semibold text-white/75 sm:px-6 md:px-8">
          <router-link to="/places" class="hover:text-white">สถานที่ธรรมชาติ</router-link>
          <span class="material-symbols-rounded icon-16 opacity-60">chevron_right</span>
          <span class="truncate text-white">{{ place.name }}</span>
        </nav>

        <div class="flex-1"></div>

        <div class="place-hero__content relative z-10 mx-auto w-full max-w-6xl px-4 pb-20 sm:px-6 md:px-8 md:pb-24">
          <div class="flex flex-wrap gap-2">
            <span v-if="place.type_label" class="hero-tag">{{ place.type_label }}</span>
            <span v-if="bestNow" class="hero-tag hero-tag--accent">
              <span class="material-symbols-rounded icon-16">wb_sunny</span>
              ช่วงพีคเดือนนี้
            </span>
            <span v-else-if="closedNow" class="hero-tag hero-tag--closed">ปิดเดือนนี้</span>
          </div>
          <h1 class="mt-4 text-3xl font-black leading-tight tracking-tight text-white sm:text-4xl md:text-5xl">{{ place.name }}</h1>
          <p class="mt-3 flex items-center gap-1.5 text-[15px] font-semibold text-white/85">
            <span class="material-symbols-rounded icon-20">location_on</span>
            {{ [place.park, place.province, place.region_label].filter(Boolean).join(' · ') }}
          </p>
        </div>
      </section>

      <div class="mx-auto max-w-6xl px-4 sm:px-6 md:px-8">
        <!-- ── ตัวเลขสำคัญ ลอยทับขอบล่างภาพปก ── -->
        <section v-if="facts.length" class="facts relative z-10 -mt-12 grid grid-cols-2 md:flex">
          <div v-for="fact in facts" :key="fact.label" class="fact">
            <span class="fact__icon material-symbols-rounded">{{ fact.icon }}</span>
            <p class="fact__value">
              <span v-if="fact.number != null" v-count-up="fact.number"></span>
              <span v-else>{{ fact.text }}</span>
              <span v-if="fact.unit" class="fact__unit">{{ fact.unit }}</span>
            </p>
            <p class="fact__label">
              {{ fact.label }}
              <router-link v-if="fact.link" :to="fact.link" class="ml-1 text-[var(--color-accent)] hover:underline">คืออะไร</router-link>
            </p>
          </div>
        </section>

        <div class="mt-12 grid grid-cols-1 gap-8 lg:grid-cols-12 lg:gap-12">
          <!-- ── เนื้อหาหลัก ── -->
          <div class="space-y-10 lg:col-span-7">
            <section v-if="place.summary || place.description" v-reveal>
              <p class="section-kicker">เกี่ยวกับที่นี่</p>
              <p v-if="place.summary" class="mt-3 text-xl font-bold leading-relaxed text-[var(--color-text-dark)]">{{ place.summary }}</p>
              <p v-if="place.description" class="mt-4 whitespace-pre-line text-[16px] leading-[1.9] text-[var(--color-text-mid)]">{{ place.description }}</p>
            </section>

            <section v-if="place.highlights?.length" v-reveal>
              <p class="section-kicker">ไฮไลต์</p>
              <ul v-reveal.stagger class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <li v-for="(item, i) in place.highlights" :key="i" class="flex items-start gap-3 rounded-2xl border border-[var(--color-sand-dark)] bg-white p-4">
                  <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[var(--color-accent-tint)] text-[var(--color-accent)]">
                    <span class="material-symbols-rounded icon-18" style="font-variation-settings:'FILL' 1">star</span>
                  </span>
                  <span class="pt-1 text-[15px] font-semibold leading-snug text-[var(--color-text-dark)]">{{ item }}</span>
                </li>
              </ul>
            </section>

            <section v-if="place.know_before?.length" v-reveal class="rounded-3xl border border-amber-200 bg-amber-50/60 p-6">
              <p class="flex items-center gap-2 text-[15px] font-extrabold text-amber-800">
                <span class="material-symbols-rounded icon-20">info</span>
                ต้องรู้ก่อนไป
              </p>
              <ul class="mt-4 space-y-3">
                <li v-for="(item, i) in place.know_before" :key="i" class="flex gap-3 text-[15px] leading-relaxed text-[var(--color-text-dark)]">
                  <span class="mt-2.5 h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                  {{ item }}
                </li>
              </ul>
            </section>

            <section v-if="place.gallery?.length" v-reveal>
              <p class="section-kicker">ภาพจากที่นี่</p>
              <div v-reveal.stagger:scale class="gallery mt-4">
                <a
                  v-for="(photo, i) in place.gallery"
                  :key="i"
                  :href="photo"
                  target="_blank"
                  rel="noopener"
                  class="zoom-media gallery__item"
                >
                  <img :src="photo" :alt="`${place.name} ภาพที่ ${i + 1}`" loading="lazy" />
                </a>
              </div>
            </section>
          </div>

          <!-- ── ข้างขวา: ปฏิทิน แผนที่ ทริป ── -->
          <aside class="space-y-6 lg:col-span-5">
            <section v-reveal class="side-card">
              <p class="section-kicker">ไปเดือนไหนดี</p>
              <MonthStrip class="mt-4" size="lg" :best="place.best_months || []" :closed="place.closed_months || []" />
              <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1.5 text-[12px] font-semibold text-[var(--color-text-muted)]">
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded bg-[var(--color-accent)]"></span>ควรไป</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded bg-[#FEE4E2]"></span>ปิด</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded bg-[var(--color-sand-dark)]"></span>ไปได้แต่ไม่ใช่ช่วงพีค</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded border-2 border-[var(--color-text-dark)]"></span>เดือนนี้</span>
              </div>
              <p v-if="place.season_note" class="mt-4 text-[15px] leading-relaxed text-[var(--color-text-dark)]">{{ place.season_note }}</p>
              <p v-if="place.closure_note" class="mt-3 flex gap-2 rounded-2xl bg-[#FEF3F2] p-3 text-[14px] font-semibold leading-relaxed text-[#B42318]">
                <span class="material-symbols-rounded icon-18 shrink-0">block</span>
                {{ place.closure_note }}
              </p>
            </section>

            <section v-if="place.latitude && place.longitude" v-reveal class="side-card overflow-hidden !p-0">
              <div class="flex items-center justify-between gap-3 p-6 pb-4">
                <p class="section-kicker">อยู่ตรงไหน</p>
                <a :href="mapsUrl" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-[13px] font-bold text-[var(--color-accent)] hover:underline">
                  เปิดใน Google Maps
                  <span class="material-symbols-rounded icon-16">open_in_new</span>
                </a>
              </div>
              <div ref="mapEl" class="h-[260px] w-full bg-[var(--color-sand-dark)]"></div>
            </section>

            <!-- ทริปที่ไปที่นี่ — วางท้ายสุดโดยตั้งใจ หน้านี้เป็นหน้าข้อมูล ไม่ใช่หน้าขาย -->
            <section v-if="place.trips?.length" v-reveal class="side-card">
              <p class="section-kicker">ถ้าอยากไปกับกลุ่ม</p>
              <p class="mt-1 text-[13px] text-[var(--color-text-muted)]">ทริปของเราที่พาไปที่นี่</p>
              <ul class="mt-4 space-y-2">
                <li v-for="trip in place.trips" :key="trip.id">
                  <router-link :to="`/trips/${trip.slug}`" class="group -mx-2 flex items-center gap-3 rounded-2xl p-2 transition-colors hover:bg-[var(--color-sand)]">
                    <span class="zoom-media h-16 w-16 shrink-0 rounded-xl bg-[var(--color-sand-dark)]">
                      <img v-if="trip.cover_image" :src="trip.cover_image" :alt="trip.title" loading="lazy" class="h-full w-full object-cover" />
                    </span>
                    <span class="min-w-0 flex-1">
                      <span class="block truncate font-bold text-[var(--color-text-dark)] group-hover:text-[var(--color-accent)]">{{ trip.title }}</span>
                      <span class="mt-0.5 block text-[13px] text-[var(--color-text-muted)]">
                        <template v-if="trip.upcoming_count">รอบถัดไป {{ trip.next_departure_label }} · อีก {{ trip.upcoming_count }} รอบ</template>
                        <template v-else>ยังไม่มีรอบเปิด</template>
                      </span>
                    </span>
                    <span class="material-symbols-rounded icon-20 text-[var(--color-text-muted)] transition-transform group-hover:translate-x-1">chevron_right</span>
                  </router-link>
                </li>
              </ul>
            </section>
          </aside>
        </div>

        <GuideNextLinks current="/places" class="mt-20" />
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '../lib/axios';
import { loadLeaflet } from '../lib/leaflet';
import MonthStrip from '../components/MonthStrip.vue';
import GuideNextLinks from '../components/GuideNextLinks.vue';

const route = useRoute();

const loading = ref(true);
const notFound = ref(false);
const place = ref(null);
const mapEl = ref(null);

let map = null;

const nowMonth = Number(new Date().toLocaleDateString('en-US', { timeZone: 'Asia/Bangkok', month: 'numeric' }));
const closedNow = computed(() => (place.value?.closed_months || []).includes(nowMonth));
const bestNow = computed(() => !closedNow.value && (place.value?.best_months || []).includes(nowMonth));

/** ตัวเลขสำคัญ — ขึ้นเฉพาะที่แอดมินกรอกไว้ */
const facts = computed(() => {
  const p = place.value;
  if (!p) return [];
  const out = [];
  if (p.elevation_m) out.push({ icon: 'altitude', number: p.elevation_m, unit: 'ม.', label: 'เหนือระดับน้ำทะเล' });
  if (p.trail_distance_km) out.push({ icon: 'route', number: p.trail_distance_km, unit: 'กม.', label: 'ระยะเดิน' });
  if (p.elevation_gain_m) out.push({ icon: 'trending_up', number: p.elevation_gain_m, unit: 'ม.', label: 'ที่ต้องไต่ขึ้น' });
  if (p.difficulty_label) out.push({ icon: 'hiking', text: p.difficulty_label, label: 'ระดับความยาก', link: '/difficulty' });
  return out;
});

const mapsUrl = computed(() => {
  const p = place.value;
  return p ? `https://www.google.com/maps/search/?api=1&query=${p.latitude},${p.longitude}` : '#';
});

async function drawMap() {
  const p = place.value;
  if (!p?.latitude || !p?.longitude) return;

  const L = await loadLeaflet();
  await nextTick();

  if (!mapEl.value || map) return;

  map = L.map(mapEl.value, { zoomControl: false, attributionControl: true, scrollWheelZoom: false })
    .setView([p.latitude, p.longitude], 11);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; OpenStreetMap',
  }).addTo(map);

  L.circleMarker([p.latitude, p.longitude], {
    radius: 9,
    color: '#fff',
    weight: 3,
    fillColor: '#047857',
    fillOpacity: 1,
  }).addTo(map);
}

function destroyMap() {
  map?.remove();
  map = null;
}

async function load(slug) {
  loading.value = true;
  notFound.value = false;
  place.value = null;
  destroyMap();

  try {
    const res = await api.get(`/places/${encodeURIComponent(slug)}`);
    place.value = res.data?.data;
    document.title = `${place.value.name} | ลุยเลเขา`;
  } catch (err) {
    if (err.response?.status === 404) notFound.value = true;
  } finally {
    loading.value = false;
  }

  await drawMap();
}

watch(() => route.params.slug, (slug) => { if (slug) load(slug); }, { immediate: true });

onBeforeUnmount(destroyMap);
</script>

<style scoped>
.place-hero__img {
  animation: placeSettle 12s var(--ease-out) forwards;
}
@keyframes placeSettle {
  from { transform: scale(1.1); }
  to   { transform: scale(1); }
}
.place-hero__content > * {
  animation: placeIn 0.8s var(--ease-out) backwards;
}
.place-hero__content > :nth-child(1) { animation-delay: 0.05s; }
.place-hero__content > :nth-child(2) { animation-delay: 0.15s; }
.place-hero__content > :nth-child(3) { animation-delay: 0.25s; }
@keyframes placeIn {
  from { opacity: 0; transform: translateY(18px); }
}

.hero-tag {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.35rem 0.85rem;
  border-radius: 999px;
  border: 1px solid rgb(255 255 255 / 0.2);
  background-color: rgb(255 255 255 / 0.14);
  backdrop-filter: blur(12px);
  font-size: 13px;
  font-weight: 700;
  color: #fff;
}
.hero-tag--accent { border-color: transparent; background-color: var(--color-accent); }
.hero-tag--closed { border-color: transparent; background-color: #FEE4E2; color: #B42318; }

.facts {
  overflow: hidden;
  border-radius: 1.5rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  animation: placeIn 0.8s var(--ease-out) 0.3s backwards;
}
.fact {
  flex: 1;
  padding: 1.25rem 1.5rem;
  border-color: var(--color-sand-dark);
}
.fact + .fact { border-left: 1px solid var(--color-sand-dark); }
@media (max-width: 767px) {
  .fact:nth-child(odd) { border-left: 0; }
  .fact:nth-child(n + 3) { border-top: 1px solid var(--color-sand-dark); }
}
.fact .fact__icon {
  font-size: 22px;
  color: var(--color-accent);
}
.fact__value {
  margin-top: 0.4rem;
  font-size: 1.75rem;
  font-weight: 900;
  line-height: 1.1;
  letter-spacing: -0.02em;
  color: var(--color-text-dark);
  font-variant-numeric: tabular-nums;
}
.fact__unit {
  margin-left: 0.25rem;
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--color-text-muted);
}
.fact__label {
  margin-top: 0.25rem;
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-muted);
}

.section-kicker {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 15px;
  font-weight: 800;
  color: var(--color-text-dark);
}
.section-kicker::before {
  content: '';
  width: 1.5rem;
  height: 2px;
  border-radius: 2px;
  background-color: var(--color-accent);
}

.side-card {
  padding: 1.5rem;
  border-radius: 1.5rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
}

.gallery {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem;
}
.gallery__item {
  display: block;
  aspect-ratio: 1;
  border-radius: 1rem;
  background-color: var(--color-sand-dark);
}
.gallery__item:first-child {
  grid-column: span 2;
  grid-row: span 2;
}
.gallery__item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

@media (prefers-reduced-motion: reduce) {
  .place-hero__img,
  .place-hero__content > *,
  .facts { animation: none; }
}
</style>
