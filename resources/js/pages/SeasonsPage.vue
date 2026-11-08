<template>
  <div class="font-anuphan bg-[var(--color-sand)] min-h-screen pb-24">
    <PageHero
      image="/images/phusoidao.webp"
      title="เดือนไหนไปไหนดี"
      subtitle="ปฏิทินธรรมชาติของไทยทั้งปี ที่ไหนอยู่ในช่วงพีค ที่ไหนปิด ใช้วางแผนล่วงหน้าได้แม้ยังไม่คิดจะจองอะไร"
    />

    <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
      <div v-if="loading" class="relative z-10 -mt-7 space-y-6" aria-hidden="true">
        <div class="h-16 rounded-3xl bg-white border border-[var(--color-sand-dark)]"></div>
        <div class="h-56 rounded-3xl shimmer"></div>
      </div>

      <div v-else-if="!hasAnyData" class="relative z-10 -mt-7 rounded-3xl bg-white border border-[var(--color-sand-dark)] px-6 py-16 text-center">
        <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-[var(--color-accent-tint)] text-[var(--color-accent)]">
          <span class="material-symbols-rounded icon-32">calendar_month</span>
        </span>
        <p class="mt-4 text-lg font-extrabold text-[var(--color-text-dark)]">ยังไม่มีข้อมูลฤดูกาล</p>
        <p class="mt-1 text-sm text-[var(--color-text-muted)]">ทีมงานกำลังทยอยเพิ่มข้อมูลของแต่ละสถานที่</p>
      </div>

      <template v-else>
        <!-- แถบเลือกเดือน — ไฮไลต์ชิ้นเดียวเลื่อนไปหาเดือนที่เลือก -->
        <div class="month-bar sticky top-16 z-30 -mt-7">
          <div ref="monthBarEl" class="relative flex overflow-x-auto no-scrollbar" role="tablist" aria-label="เลือกเดือน">
            <span class="month-bar__ink" :style="inkStyle" aria-hidden="true"></span>
            <button
              v-for="month in months"
              :key="month.month"
              type="button"
              role="tab"
              :aria-selected="month.month === selected"
              :data-month="month.month"
              class="month-bar__btn"
              :class="{ 'is-active': month.month === selected }"
              @click="selected = month.month"
            >
              {{ THAI_MONTHS_SHORT[month.month - 1] }}
              <span v-if="month.month === currentMonth" class="month-bar__now" aria-label="เดือนนี้"></span>
            </button>
          </div>
        </div>

        <Transition :name="direction > 0 ? 'month-next' : 'month-prev'" mode="out-in">
          <div v-if="active" :key="active.month" class="mt-8">
            <!-- หัวเดือน -->
            <section class="month-head">
              <div class="min-w-0">
                <p class="flex items-center gap-2 text-[14px] font-bold text-white/70">
                  <span class="material-symbols-rounded icon-20 text-white/80">{{ seasonIcon(active.season) }}</span>
                  {{ active.season_label }}<span v-if="active.month === currentMonth"> · เดือนนี้</span>
                </p>
                <h2 class="mt-1 text-4xl md:text-5xl font-black tracking-tight text-white">{{ active.label }}</h2>
                <p class="mt-3 text-[15px] text-white/75">
                  <template v-if="active.best.length">
                    <span class="font-extrabold text-white">{{ active.best.length }}</span> ที่อยู่ในช่วงที่ควรไป
                  </template>
                  <template v-else>เดือนนี้ไม่มีที่ไหนอยู่ในช่วงพีค เป็นช่วงพักของหลายเส้นทาง</template>
                  <template v-if="active.closed.length"> · <span class="font-extrabold text-white">{{ active.closed.length }}</span> ที่ปิด</template>
                </p>
              </div>
              <div class="flex shrink-0 gap-2">
                <button type="button" class="month-step press" aria-label="เดือนก่อนหน้า" @click="step(-1)">
                  <span class="material-symbols-rounded">chevron_left</span>
                </button>
                <button type="button" class="month-step press" aria-label="เดือนถัดไป" @click="step(1)">
                  <span class="material-symbols-rounded">chevron_right</span>
                </button>
              </div>
            </section>

            <!-- ที่ที่ควรไป -->
            <section v-if="active.best.length" class="mt-8">
              <h3 class="section-kicker">ช่วงที่ควรไป</h3>
              <div v-reveal.stagger class="mt-4 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <router-link v-for="place in active.best" :key="place.id" :to="`/places/${place.slug}`" class="best-card group">
                  <span class="zoom-media relative block aspect-[4/3] bg-[var(--color-primary)]">
                    <img v-if="place.cover_image" :src="place.cover_image" :alt="place.name" loading="lazy" class="h-full w-full object-cover" />
                    <span class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/10 to-transparent"></span>
                    <span class="absolute inset-x-4 bottom-4">
                      <span class="block text-lg font-extrabold leading-snug text-white">{{ place.name }}</span>
                      <span class="mt-0.5 block text-[13px] font-semibold text-white/80">
                        {{ [place.province, place.region_label].filter(Boolean).join(' · ') }}
                      </span>
                    </span>
                  </span>
                  <span v-if="place.summary" class="block p-4 text-[14px] leading-relaxed text-[var(--color-text-mid)] line-clamp-2">{{ place.summary }}</span>
                </router-link>
              </div>
            </section>

            <!-- ที่ที่ปิด -->
            <section v-if="active.closed.length" class="mt-8 rounded-3xl border border-[var(--color-sand-dark)] bg-white p-6">
              <h3 class="flex items-center gap-2 text-[15px] font-extrabold text-[#B42318]">
                <span class="material-symbols-rounded icon-20">block</span>
                ปิดในเดือนนี้
              </h3>
              <p class="mt-1 text-[13px] text-[var(--color-text-muted)]">อย่าเพิ่งวางแผนไป ยังเข้าไม่ได้</p>
              <ul class="mt-4 divide-y divide-[var(--color-sand-dark)]">
                <li v-for="place in active.closed" :key="place.slug">
                  <router-link :to="`/places/${place.slug}`" class="group flex items-center gap-3 py-3">
                    <span class="min-w-0 flex-1">
                      <span class="block font-bold text-[var(--color-text-dark)] group-hover:text-[var(--color-accent)]">{{ place.name }}</span>
                      <span v-if="place.closure_note" class="mt-0.5 block text-[13px] leading-snug text-[var(--color-text-muted)]">{{ place.closure_note }}</span>
                    </span>
                    <span class="material-symbols-rounded icon-20 text-[var(--color-text-muted)] transition-transform group-hover:translate-x-1">chevron_right</span>
                  </router-link>
                </li>
              </ul>
            </section>
          </div>
        </Transition>

        <!-- ภาพรวมทั้งปี — ทุกสถานที่ในตารางเดียว กดหัวเดือนเพื่อดูรายละเอียดเดือนนั้น -->
        <section v-if="yearRows.length" v-reveal class="mt-16 rounded-3xl border border-[var(--color-sand-dark)] bg-white p-6 md:p-8">
          <h3 class="section-kicker">ภาพรวมทั้งปี</h3>
          <p class="mt-1 text-[13px] text-[var(--color-text-muted)]">เขียว = ช่วงที่ควรไป · แดง = ปิด · เทา = ไปได้แต่ไม่ใช่ช่วงพีค</p>

          <div class="mt-6 overflow-x-auto no-scrollbar">
            <div class="year-grid">
              <span></span>
              <div class="year-grid__months">
                <button
                  v-for="m in 12"
                  :key="m"
                  type="button"
                  class="year-grid__month"
                  :class="{ 'is-active': m === selected, 'is-now': m === currentMonth }"
                  @click="selectAndScroll(m)"
                >{{ THAI_MONTHS_SHORT[m - 1] }}</button>
              </div>

              <template v-for="row in yearRows" :key="row.slug">
                <router-link :to="`/places/${row.slug}`" class="year-grid__name">{{ row.name }}</router-link>
                <MonthStrip :best="row.best" :closed="row.closed" :selected="selected" class="year-grid__strip" />
              </template>
            </div>
          </div>
        </section>

        <p class="mt-6 text-center text-[13px] text-[var(--color-text-muted)]">
          ข้อมูลช่วงปิดอ้างอิงประกาศของอุทยาน อาจเปลี่ยนได้ตามสภาพอากาศแต่ละปี
        </p>
      </template>

      <GuideNextLinks current="/seasons" class="mt-20" />
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import api from '../lib/axios';
import { THAI_MONTHS_SHORT } from '../lib/thaiDate';
import PageHero from '../components/PageHero.vue';
import MonthStrip from '../components/MonthStrip.vue';
import GuideNextLinks from '../components/GuideNextLinks.vue';

const loading = ref(true);
const months = ref([]);
const currentMonth = ref(1);
const selected = ref(1);
const direction = ref(1);

const active = computed(() => months.value.find(m => m.month === selected.value) || null);

const hasAnyData = computed(
  () => months.value.some(m => m.best.length > 0 || m.closed.length > 0),
);

const SEASON_ICONS = { winter: 'ac_unit', summer: 'wb_sunny', hot: 'wb_sunny', rainy: 'rainy', rain: 'rainy' };
const seasonIcon = (season) => SEASON_ICONS[season] || 'calendar_month';

watch(selected, (to, from) => {
  // ข้ามปี (ธ.ค. → ม.ค.) ถือว่าไปข้างหน้า
  direction.value = to > from || (from === 12 && to === 1) ? 1 : -1;
  if (from === 1 && to === 12) direction.value = -1;
  nextTick(updateInk);
});

function step(delta) {
  selected.value = ((selected.value - 1 + delta + 12) % 12) + 1;
}

function selectAndScroll(m) {
  selected.value = m;
  monthBarEl.value?.closest('.month-bar')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/**
 * กลับด้านข้อมูล "เดือน → สถานที่" เป็น "สถานที่ → เดือน" สำหรับตารางทั้งปี
 * รายการ best มี id/รูป ส่วน closed มีแค่ชื่อกับ slug — ใช้ slug เป็นตัวรวม
 */
const yearRows = computed(() => {
  const rows = new Map();
  const rowFor = (place) => {
    if (!rows.has(place.slug)) rows.set(place.slug, { slug: place.slug, name: place.name, best: [], closed: [] });
    return rows.get(place.slug);
  };
  months.value.forEach((m) => {
    m.best.forEach((p) => rowFor(p).best.push(m.month));
    m.closed.forEach((p) => rowFor(p).closed.push(m.month));
  });
  return [...rows.values()].sort((a, b) => a.name.localeCompare(b.name, 'th'));
});

// ไฮไลต์ของแถบเดือน
const monthBarEl = ref(null);
const inkStyle = ref({ opacity: 0 });
function updateInk() {
  const bar = monthBarEl.value;
  const btn = bar?.querySelector(`[data-month="${selected.value}"]`);
  if (!btn) return;
  inkStyle.value = { width: `${btn.offsetWidth}px`, transform: `translateX(${btn.offsetLeft}px)`, opacity: 1 };
  if (bar.scrollWidth > bar.clientWidth) {
    bar.scrollTo({ left: btn.offsetLeft - (bar.clientWidth - btn.offsetWidth) / 2, behavior: 'smooth' });
  }
}

onMounted(async () => {
  try {
    const res = await api.get('/places/seasons');
    const data = res.data?.data || {};
    months.value = data.months || [];
    currentMonth.value = data.current_month || 1;
    // เปิดมาที่เดือนปัจจุบันก่อน เพราะคำถามส่วนใหญ่คือ "เดือนนี้ไปไหนได้"
    selected.value = currentMonth.value;
  } finally {
    loading.value = false;
  }
  await nextTick();
  updateInk();
  window.addEventListener('resize', updateInk, { passive: true });
});

onBeforeUnmount(() => window.removeEventListener('resize', updateInk));
</script>

<style scoped>
.month-bar {
  padding: 0.4rem;
  border-radius: 1.5rem;
  border: 1px solid var(--color-sand-dark);
  background-color: rgb(255 255 255 / 0.92);
  backdrop-filter: blur(16px);
}
.month-bar__btn {
  position: relative;
  z-index: 1;
  flex: 1 0 auto;
  min-width: 3.5rem;
  padding: 0.7rem 0.5rem;
  border-radius: 999px;
  font-size: 14px;
  font-weight: 800;
  color: var(--color-text-mid);
  transition: color 0.3s ease;
}
.month-bar__btn:hover { color: var(--color-text-dark); }
.month-bar__btn.is-active { color: #fff; }
.month-bar__ink {
  position: absolute;
  top: 0;
  bottom: 0;
  left: 0;
  border-radius: 999px;
  background-color: var(--color-accent);
  transition: transform 0.45s var(--ease-out), width 0.45s var(--ease-out), opacity 0.2s ease;
}
.month-bar__now {
  position: absolute;
  top: 0.35rem;
  right: 0.55rem;
  width: 6px;
  height: 6px;
  border-radius: 999px;
  background-color: #F59E0B;
}

.month-head {
  position: relative;
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 1.5rem;
  overflow: hidden;
  padding: 2rem;
  border-radius: 2rem;
  background:
    radial-gradient(120% 120% at 100% 0%, color-mix(in srgb, var(--color-accent-light) 35%, transparent), transparent 55%),
    linear-gradient(150deg, var(--color-primary-light), var(--color-primary));
}
@media (min-width: 768px) {
  .month-head { padding: 2.5rem 3rem; }
}
/* จอแคบ: ปุ่มเลื่อนเดือนลงไปอยู่ใต้ข้อความ ไม่เบียดให้ข้อความเหลือคอลัมน์แคบ */
@media (max-width: 639px) {
  .month-head { flex-direction: column; align-items: flex-start; padding: 1.75rem 1.5rem; }
}
.month-step {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.75rem;
  height: 2.75rem;
  border-radius: 999px;
  border: 1px solid rgb(255 255 255 / 0.25);
  color: #fff;
  transition: background-color 0.2s ease;
}
.month-step:hover { background-color: rgb(255 255 255 / 0.12); }

.month-next-enter-active,
.month-prev-enter-active { transition: opacity 0.4s var(--ease-out), transform 0.4s var(--ease-out); }
.month-next-leave-active,
.month-prev-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.month-next-enter-from { opacity: 0; transform: translateX(28px); }
.month-next-leave-to   { opacity: 0; transform: translateX(-12px); }
.month-prev-enter-from { opacity: 0; transform: translateX(-28px); }
.month-prev-leave-to   { opacity: 0; transform: translateX(12px); }

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

.best-card {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border-radius: 1.5rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  transition: border-color 0.25s ease;
}
.best-card:hover { border-color: color-mix(in srgb, var(--color-accent) 35%, transparent); }

.year-grid {
  display: grid;
  grid-template-columns: minmax(8rem, 12rem) minmax(32rem, 1fr);
  align-items: center;
  column-gap: 1.25rem;
  row-gap: 1rem;
}
.year-grid__months {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  gap: 3px;
}
.year-grid__month {
  padding: 0.3rem 0;
  border-radius: 0.5rem;
  font-size: 12px;
  font-weight: 800;
  color: var(--color-text-muted);
  transition: background-color 0.2s ease, color 0.2s ease;
}
.year-grid__month:hover { background-color: var(--color-sand); color: var(--color-text-dark); }
.year-grid__month.is-now { color: var(--color-text-dark); }
.year-grid__month.is-active { background-color: var(--color-text-dark); color: #fff; }
.year-grid__name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 14px;
  font-weight: 700;
  color: var(--color-text-dark);
}
.year-grid__name:hover { color: var(--color-accent); }
.year-grid__strip :deep(.month-strip__cell) { height: 10px; border-radius: 4px; }
.year-grid__strip :deep(.month-strip__cell.is-selected) { outline: 2px solid var(--color-text-dark); outline-offset: 1px; }

.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }

@media (prefers-reduced-motion: reduce) {
  .month-next-enter-active, .month-prev-enter-active,
  .month-next-leave-active, .month-prev-leave-active { transition: none; }
  .month-bar__ink { transition: none; }
}
</style>
