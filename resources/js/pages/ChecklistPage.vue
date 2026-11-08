<template>
  <div class="font-anuphan bg-[var(--color-sand)] min-h-screen pb-24 print:bg-white print:pb-0">
    <PageHero
      class="print:hidden"
      image="/images/hiking.webp"
      :title="content.title"
      :subtitle="content.intro"
      :loading="loading"
      compact
    />

    <div class="max-w-6xl mx-auto px-4 sm:px-6 md:px-8">
      <!-- หัวกระดาษตอนพิมพ์เท่านั้น -->
      <header class="hidden print:block print:mb-6">
        <h1 class="text-2xl font-bold text-black">{{ content.title }}</h1>
        <p class="text-sm text-gray-600">{{ activeTypeLabel }} · {{ activeSeasonLabel }} · {{ activeNightsLabel }}</p>
      </header>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-12 lg:gap-10">
        <!-- ── ข้างซ้าย: ตัวเลือก + ความคืบหน้า (ค้างไว้ระหว่างเลื่อนบนจอใหญ่) ── -->
        <aside class="relative z-10 -mt-8 lg:col-span-4 print:hidden">
          <div class="space-y-4 lg:sticky lg:top-24">
            <section class="side-card">
              <div class="flex items-center gap-5">
                <!-- วงแหวนความคืบหน้า -->
                <div class="pack-ring" :class="{ 'is-done': isDone, 'is-empty': !checkedCount }">
                  <svg viewBox="0 0 80 80" aria-hidden="true">
                    <circle class="pack-ring__track" cx="40" cy="40" r="34" />
                    <circle class="pack-ring__fill" cx="40" cy="40" r="34" :style="{ strokeDashoffset: ringOffset }" />
                  </svg>
                  <span class="pack-ring__label">
                    <span v-if="isDone" class="material-symbols-rounded pack-ring__check">check</span>
                    <template v-else>{{ progress }}<small>%</small></template>
                  </span>
                </div>
                <div class="min-w-0">
                  <p class="text-[13px] font-bold text-[var(--color-text-muted)]">จัดของแล้ว</p>
                  <p class="text-2xl font-black tabular-nums text-[var(--color-text-dark)]">
                    {{ checkedCount }}<span class="text-base font-bold text-[var(--color-text-muted)]"> / {{ totalCount }}</span>
                  </p>
                  <p v-if="essentialLeft" class="mt-0.5 text-[13px] font-bold text-[#B42318]">ของสำคัญเหลือ {{ essentialLeft }} อย่าง</p>
                  <p v-else-if="totalCount" class="mt-0.5 text-[13px] font-bold text-[var(--color-accent)]">ของสำคัญครบแล้ว</p>
                </div>
              </div>

              <div class="mt-5 flex flex-wrap gap-2 border-t border-[var(--color-sand-dark)] pt-4">
                <button type="button" class="action-btn press" @click="printList">
                  <span class="material-symbols-rounded icon-18">print</span>
                  พิมพ์รายการ
                </button>
                <button v-if="checkedCount" type="button" class="action-btn action-btn--danger press" @click="clearChecks">
                  <span class="material-symbols-rounded icon-18">restart_alt</span>
                  ล้างที่ติ๊กไว้
                </button>
              </div>
            </section>

            <section class="side-card space-y-5">
              <div v-for="group in OPTION_GROUPS" :key="group.key">
                <p class="mb-2 text-[12px] font-extrabold text-[var(--color-text-muted)]">{{ group.label }}</p>
                <div class="flex flex-wrap gap-2">
                  <button
                    v-for="opt in content[group.source]"
                    :key="opt.key"
                    type="button"
                    class="chip press"
                    :class="{ 'chip--on': options[group.key] === opt.key }"
                    :aria-pressed="options[group.key] === opt.key"
                    @click="options[group.key] = opt.key"
                  >{{ opt.label }}</button>
                </div>
              </div>
            </section>
          </div>
        </aside>

        <!-- ── รายการ ── -->
        <div class="pt-2 lg:col-span-8 lg:pt-10 print:pt-0">
          <Transition name="done-banner">
            <div v-if="isDone" class="done-banner print:hidden">
              <span class="done-banner__icon material-symbols-rounded">celebration</span>
              <div>
                <p class="text-lg font-black text-white">จัดของครบแล้ว พร้อมออกเดินทาง</p>
                <p class="text-[14px] text-white/80">ขอให้สนุกกับทริป อย่าลืมเช็คพยากรณ์อากาศก่อนออกจากบ้าน</p>
              </div>
            </div>
          </Transition>

          <div v-if="loading" class="space-y-4" aria-hidden="true">
            <div v-for="n in 3" :key="n" class="h-56 rounded-3xl shimmer"></div>
          </div>

          <section
            v-for="category in visibleCategories"
            :key="category.key"
            v-reveal
            class="category print:mb-6 print:border-0 print:p-0"
          >
            <div class="flex items-center justify-between gap-3">
              <h2 class="text-[17px] font-extrabold text-[var(--color-text-dark)]">{{ category.title }}</h2>
              <span class="category__count print:hidden" :class="{ 'is-done': doneIn(category) === category.items.length }">
                <span v-if="doneIn(category) === category.items.length" class="material-symbols-rounded icon-16">check</span>
                {{ doneIn(category) }}/{{ category.items.length }}
              </span>
            </div>

            <ul class="mt-4 space-y-1">
              <li v-for="item in category.items" :key="item.label">
                <label class="item" :class="{ 'is-checked': checked[item.label] }">
                  <input
                    type="checkbox"
                    class="sr-only"
                    :checked="checked[item.label]"
                    @change="toggle(item.label)"
                  />
                  <span class="item__box" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg>
                  </span>
                  <span class="min-w-0 flex-1">
                    <span class="item__label">
                      {{ item.label }}
                      <span v-if="item.essential" class="item__essential print:hidden">ขาดไม่ได้</span>
                    </span>
                    <span v-if="item.note" class="mt-0.5 block text-[13px] leading-snug text-[var(--color-text-muted)]">{{ item.note }}</span>
                  </span>
                </label>
              </li>
            </ul>
          </section>

          <p v-if="content.footnote" class="mt-6 text-center text-[13px] text-[var(--color-text-muted)] print:hidden">
            {{ content.footnote }}
          </p>
        </div>
      </div>

      <GuideNextLinks current="/checklist" class="mt-20 print:hidden" />
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { usePageContent } from '../lib/pageContent';
import PageHero from '../components/PageHero.vue';
import GuideNextLinks from '../components/GuideNextLinks.vue';

const STORAGE_KEY = 'packing_checklist';

const { content, loading } = usePageContent('checklist', {
  title: '',
  intro: '',
  footnote: '',
  trip_types: [],
  seasons: [],
  nights: [],
  categories: [],
});

const OPTION_GROUPS = [
  { key: 'tripType', source: 'trip_types', label: 'แบบทริป' },
  { key: 'season', source: 'seasons', label: 'ช่วงเวลา' },
  { key: 'nights', source: 'nights', label: 'ระยะเวลา' },
];

const options = reactive({ tripType: '', season: '', nights: '' });
const checked = ref(loadChecked());

/**
 * รายการที่ตรงกับเงื่อนไขที่เลือก
 *
 * เงื่อนไขที่ "ว่าง" แปลว่าใช้ได้ทุกกรณี — ต้องเช็ค length ไม่ใช่แค่ความมีอยู่ของ array
 * เพราะฝั่งแอดมินส่ง [] มาเสมอเมื่อไม่ได้เลือกอะไร
 */
function matches(item) {
  if (item.trips?.length && !item.trips.includes(options.tripType)) return false;
  if (item.seasons?.length && !item.seasons.includes(options.season)) return false;
  if (item.overnight && options.nights !== 'overnight') return false;

  return true;
}

const visibleCategories = computed(() => (content.value.categories || [])
  .map(category => ({ ...category, items: (category.items || []).filter(matches) }))
  .filter(category => category.items.length > 0));

const allItems = computed(() => visibleCategories.value.flatMap(c => c.items));
const totalCount = computed(() => allItems.value.length);
const checkedCount = computed(() => allItems.value.filter(item => checked.value[item.label]).length);
const progress = computed(() => (totalCount.value ? Math.round((checkedCount.value / totalCount.value) * 100) : 0));
const isDone = computed(() => totalCount.value > 0 && checkedCount.value === totalCount.value);
const essentialLeft = computed(() => allItems.value.filter(item => item.essential && !checked.value[item.label]).length);

// เส้นรอบวงของวงแหวน r=34
const RING = 2 * Math.PI * 34;
const ringOffset = computed(() => RING * (1 - progress.value / 100));

const doneIn = (category) => category.items.filter(item => checked.value[item.label]).length;

const activeTypeLabel = computed(() => labelOf(content.value.trip_types, options.tripType));
const activeSeasonLabel = computed(() => labelOf(content.value.seasons, options.season));
const activeNightsLabel = computed(() => labelOf(content.value.nights, options.nights));

function labelOf(list, key) {
  return (list || []).find(o => o.key === key)?.label || '';
}

/**
 * ตัวเลือกมาจากหลังบ้าน จึงเลือกค่าเริ่มต้นได้ก็ต่อเมื่อโหลดเสร็จ
 * และถ้าแอดมินลบ/เปลี่ยนคีย์ที่ผู้ใช้ค้างไว้ ต้องเด้งกลับไปตัวแรกแทนที่จะได้รายการว่าง
 */
watch(content, (value) => {
  const pick = (list, current) => ((list || []).some(o => o.key === current) ? current : (list?.[0]?.key || ''));

  options.tripType = pick(value.trip_types, options.tripType);
  options.season = pick(value.seasons, options.season);
  options.nights = pick(value.nights, options.nights);
}, { immediate: true });

/**
 * เก็บสถานะติ๊กด้วยชื่อรายการ ไม่ใช่ index — สลับแบบทริปแล้วรายการเปลี่ยนลำดับ
 * แต่ของที่จัดไปแล้วต้องยังติ๊กอยู่
 */
function loadChecked() {
  try {
    return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {};
  } catch {
    return {};
  }
}

function toggle(label) {
  checked.value = { ...checked.value, [label]: !checked.value[label] };
}

function clearChecks() {
  checked.value = {};
}

function printList() {
  window.print();
}

watch(checked, (value) => {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
  } catch {
    // โหมดส่วนตัว/ปิด storage — ติ๊กได้ตามปกติ แค่ไม่จำข้ามครั้ง
  }
}, { deep: true });
</script>

<style scoped>
.side-card {
  padding: 1.5rem;
  border-radius: 1.5rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
}

.pack-ring {
  position: relative;
  width: 5.5rem;
  height: 5.5rem;
  flex-shrink: 0;
}
.pack-ring svg {
  width: 100%;
  height: 100%;
  transform: rotate(-90deg);
}
.pack-ring circle {
  fill: none;
  stroke-width: 8;
}
.pack-ring__track { stroke: var(--color-sand-dark); }
.pack-ring__fill {
  stroke: var(--color-accent);
  stroke-linecap: round;
  stroke-dasharray: 213.63;
  transition: stroke-dashoffset 0.6s var(--ease-out), stroke 0.3s ease;
}
.pack-ring__label {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.35rem;
  font-weight: 900;
  color: var(--color-text-dark);
  font-variant-numeric: tabular-nums;
}
.pack-ring__label small { font-size: 0.7rem; font-weight: 800; margin-left: 1px; }
.pack-ring.is-empty .pack-ring__fill { stroke-opacity: 0; }
.pack-ring.is-done .pack-ring__label { color: var(--color-accent); }
.pack-ring .pack-ring__check {
  font-size: 36px;
  font-variation-settings: 'wght' 700;
  animation: heart-pop 0.5s var(--ease-spring);
}

.action-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.5rem 0.9rem;
  border-radius: 999px;
  background-color: var(--color-sand);
  font-size: 13px;
  font-weight: 700;
  color: var(--color-text-dark);
  transition: background-color 0.2s ease;
}
.action-btn:hover { background-color: var(--color-sand-dark); }
.action-btn--danger { color: #B42318; }

.chip {
  padding: 0.5rem 0.95rem;
  border-radius: 999px;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  font-size: 13px;
  font-weight: 700;
  color: var(--color-text-mid);
  transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease;
}
.chip:hover { border-color: color-mix(in srgb, var(--color-accent) 40%, transparent); }
.chip--on {
  border-color: var(--color-accent);
  background-color: var(--color-accent);
  color: #fff;
}

.done-banner {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1rem;
  padding: 1.25rem 1.5rem;
  border-radius: 1.5rem;
  background: linear-gradient(135deg, var(--color-accent), var(--color-primary-light));
}
.done-banner .done-banner__icon {
  font-size: 36px;
  color: #fff;
  animation: heart-pop 0.6s var(--ease-spring) 0.15s backwards;
}
.done-banner-enter-active { transition: opacity 0.4s var(--ease-out), transform 0.5s var(--ease-spring); }
.done-banner-leave-active { transition: opacity 0.2s ease; }
.done-banner-enter-from { opacity: 0; transform: translateY(-8px) scale(0.97); }
.done-banner-leave-to { opacity: 0; }

.category {
  margin-bottom: 1rem;
  padding: 1.5rem;
  border-radius: 1.5rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
}
.category__count {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  padding: 0.25rem 0.7rem;
  border-radius: 999px;
  background-color: var(--color-sand);
  font-size: 12px;
  font-weight: 800;
  color: var(--color-text-muted);
  font-variant-numeric: tabular-nums;
  transition: background-color 0.3s ease, color 0.3s ease;
}
.category__count.is-done { background-color: var(--color-accent-tint); color: var(--color-accent); }

.item {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
  margin: 0 -0.75rem;
  padding: 0.6rem 0.75rem;
  border-radius: 1rem;
  cursor: pointer;
  transition: background-color 0.2s ease;
}
.item:hover { background-color: var(--color-sand); }
.item:has(input:focus-visible) { box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-accent) 35%, transparent); }
.item__box {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 1.5rem;
  height: 1.5rem;
  margin-top: 0.05rem;
  flex-shrink: 0;
  border-radius: 0.5rem;
  border: 2px solid #CBD5D1;
  background-color: #fff;
  transition: background-color 0.2s ease, border-color 0.2s ease, transform 0.2s var(--ease-spring);
}
.item__box svg {
  width: 1rem;
  height: 1rem;
  fill: none;
  stroke: #fff;
  stroke-width: 3;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-dasharray: 22;
  stroke-dashoffset: 22;
  transition: stroke-dashoffset 0.3s var(--ease-out) 0.05s;
}
.item.is-checked .item__box {
  border-color: var(--color-accent);
  background-color: var(--color-accent);
  transform: scale(1.08);
}
.item.is-checked .item__box svg { stroke-dashoffset: 0; }
.item:active .item__box { transform: scale(0.9); }
.item__label {
  font-size: 15px;
  font-weight: 600;
  line-height: 1.5;
  color: var(--color-text-dark);
  text-decoration: line-through;
  text-decoration-color: transparent;
  transition: color 0.25s ease, text-decoration-color 0.25s ease;
}
.item.is-checked .item__label {
  color: var(--color-text-muted);
  text-decoration-color: currentColor;
}
.item__essential {
  display: inline-block;
  margin-left: 0.4rem;
  padding: 0.05rem 0.45rem;
  border-radius: 0.4rem;
  background-color: #FEF3F2;
  font-size: 11px;
  font-weight: 800;
  color: #B42318;
  text-decoration: none;
  vertical-align: 1px;
}

@media print {
  .item { padding: 0.25rem 0; margin: 0; }
  .item__box { border-color: #000; }
}

@media (prefers-reduced-motion: reduce) {
  .pack-ring__fill, .item__box, .item__box svg, .item__label { transition: none; }
  .pack-ring .pack-ring__check, .done-banner .done-banner__icon { animation: none; }
}
</style>
