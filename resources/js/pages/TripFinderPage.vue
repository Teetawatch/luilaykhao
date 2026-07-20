<template>
  <div class="min-h-screen bg-[var(--color-sand)] py-10 md:py-16 px-4">
    <div class="max-w-3xl mx-auto">

      <!-- ─── Quiz ─── -->
      <template v-if="phase === 'quiz'">
        <div class="text-center mb-8">
          <span class="inline-flex items-center gap-2 text-[var(--color-accent)] font-extrabold text-sm tracking-wide uppercase mb-3">
            <span class="material-symbols-rounded text-[18px]">auto_awesome</span> ค้นหาทริปที่ใช่
          </span>
          <h1 class="text-3xl md:text-4xl font-black text-[var(--color-text-dark)] leading-tight">{{ steps[step].title }}</h1>
          <p class="text-[var(--color-text-muted)] text-base md:text-lg font-medium mt-3">{{ steps[step].subtitle }}</p>
        </div>

        <!-- progress dots -->
        <div class="flex items-center justify-center gap-2.5 mb-10">
          <span v-for="(s, i) in steps" :key="i"
            class="h-2 rounded-full transition-all duration-500"
            :class="i === step ? 'w-8 bg-[var(--color-accent)]' : i < step ? 'w-2 bg-[var(--color-accent)]/50' : 'w-2 bg-[var(--color-sand-dark)]'"></span>
        </div>

        <Transition :name="transitionName" mode="out-in">
          <div :key="step" class="grid gap-4" :class="step === 0 ? 'sm:grid-cols-2' : 'sm:grid-cols-2'">
            <button v-for="opt in steps[step].options" :key="opt.key"
              @click="choose(opt)"
              class="group relative text-left bg-white rounded-[1.75rem] p-6 border-2 border-transparent hover:-translate-y-1.5 hover:border-[var(--color-accent)]/40 transition-all duration-300 cursor-pointer">
              <div class="w-14 h-14 rounded-2xl flex items-center justify-center mb-4 bg-[var(--color-accent)]/10 text-[var(--color-accent)] group-hover:bg-[var(--color-accent)] group-hover:text-white transition-all duration-300">
                <span class="material-symbols-rounded text-[28px]">{{ opt.icon }}</span>
              </div>
              <div class="font-black text-lg text-[var(--color-text-dark)]">{{ opt.label }}</div>
              <div v-if="opt.desc" class="text-sm text-[var(--color-text-muted)] font-medium mt-1">{{ opt.desc }}</div>
              <span class="material-symbols-rounded absolute top-6 right-6 text-[var(--color-sand-dark)] group-hover:text-[var(--color-accent)] group-hover:translate-x-1 transition-all duration-300">arrow_forward</span>
            </button>
          </div>
        </Transition>

        <!-- nav -->
        <div class="flex items-center justify-between mt-10">
          <button v-if="step > 0" @click="back"
            class="inline-flex items-center gap-1.5 text-[var(--color-text-mid)] hover:text-[var(--color-text-dark)] font-bold text-sm px-4 py-2.5 rounded-full hover:bg-white transition-all cursor-pointer">
            <span class="material-symbols-rounded text-[20px]">arrow_back</span> ย้อนกลับ
          </button>
          <span v-else></span>
          <button @click="skip"
            class="text-[var(--color-text-muted)] hover:text-[var(--color-accent)] font-bold text-sm px-4 py-2.5 rounded-full transition-all cursor-pointer">
            ข้ามขั้นนี้
          </button>
        </div>
      </template>

      <!-- ─── Results ─── -->
      <template v-else>
        <div class="text-center mb-8">
          <h1 class="text-3xl md:text-4xl font-black text-[var(--color-text-dark)] leading-tight">
            {{ loading ? 'กำลังค้นหา…' : results.length ? 'ทริปที่ใช่สำหรับคุณ' : 'ยังไม่เจอทริปที่ตรงเป๊ะ' }}
          </h1>
          <p v-if="selectionSummary" class="text-[var(--color-text-muted)] text-base font-medium mt-3">{{ selectionSummary }}</p>
        </div>

        <div v-if="loading" class="flex justify-center py-24">
          <div class="w-12 h-12 rounded-full border-4 border-[var(--color-sand-dark)] border-t-[var(--color-accent)] animate-spin"></div>
        </div>

        <template v-else>
          <!-- relaxation banner -->
          <div v-if="relaxedNote" class="flex items-start gap-3 bg-[var(--color-accent-light)]/15 border border-[var(--color-accent)]/25 text-[var(--color-text-dark)] rounded-2xl px-5 py-4 mb-6 font-medium">
            <span class="material-symbols-rounded text-[var(--color-accent)] shrink-0">tips_and_updates</span>
            <span>{{ relaxedNote }}</span>
          </div>

          <div v-if="results.length" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
            <TripCard v-for="(trip, i) in results" :key="trip.id" :trip="trip"
              class="animate-fade-in-up" :style="{ animationDelay: `${i * 0.07}s` }" />
          </div>

          <div v-else class="text-center py-16 bg-white rounded-[2rem] border border-gray-100">
            <span class="material-symbols-rounded text-[56px] text-[var(--color-sand-dark)]">travel_explore</span>
            <p class="text-[var(--color-text-muted)] font-medium mt-3">ลองเริ่มใหม่แล้วปรับเงื่อนไขให้กว้างขึ้นนะครับ</p>
          </div>

          <!-- actions -->
          <div class="flex flex-col sm:flex-row items-center justify-center gap-3 mt-10">
            <button @click="viewAll"
              class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-[var(--color-primary)] text-white px-8 py-4 rounded-full text-base font-extrabold hover:bg-[var(--color-accent)] transition-all duration-300 hover:-translate-y-1 cursor-pointer">
              <span class="material-symbols-rounded text-[20px]">grid_view</span> ดูทั้งหมดในหน้าทริป
            </button>
            <button @click="restart"
              class="w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-white text-[var(--color-text-dark)] px-8 py-4 rounded-full text-base font-extrabold border border-[var(--color-sand-dark)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition-all duration-300 cursor-pointer">
              <span class="material-symbols-rounded text-[20px]">restart_alt</span> เริ่มใหม่
            </button>
          </div>
        </template>
      </template>

    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useTripsStore } from '../stores/trips';
import { useCategoriesStore } from '../stores/categories';
import TripCard from '../components/TripCard.vue';

const router = useRouter();
const tripsStore = useTripsStore();
const categoriesStore = useCategoriesStore();

const phase = ref('quiz');
const step = ref(0);
const transitionName = ref('slide-next');
const loading = ref(false);
const results = ref([]);
const relaxedNote = ref('');

const answers = reactive({
  type: '', typeLabel: '',
  difficulty: '', difficultyLabel: '',
  days: null, // { label, min_days?, max_days? }
});

const difficultyOptions = [
  { key: 'easy', label: 'เริ่มต้น', desc: 'สบายๆ เหมาะกับมือใหม่', icon: 'sentiment_satisfied', value: 'easy' },
  { key: 'medium', label: 'ปานกลาง', desc: 'ท้าทายพอประมาณ', icon: 'directions_walk', value: 'medium' },
  { key: 'hard', label: 'ขาโหด', desc: 'สายลุยตัวจริง', icon: 'local_fire_department', value: 'hard' },
  { key: 'any', label: 'ระดับไหนก็ได้', desc: 'ขอแค่ได้ออกไปเที่ยว', icon: 'all_inclusive', value: '' },
];

const dayOptions = [
  { key: 'day', label: 'ไปเช้า-เย็นกลับ / 1 วัน', icon: 'wb_sunny', max_days: 1 },
  { key: 'short', label: '2–3 วัน', desc: 'เที่ยวสุดสัปดาห์', icon: 'weekend', min_days: 2, max_days: 3 },
  { key: 'long', label: '4 วันขึ้นไป', desc: 'ทริปยาวจัดเต็ม', icon: 'hiking', min_days: 4 },
  { key: 'any', label: 'กี่วันก็ได้', icon: 'all_inclusive' },
];

const typeOptions = computed(() => {
  const cats = (categoriesStore.categories || []).map(c => ({
    key: c.slug, label: c.name, icon: c.icon || 'category', value: c.slug,
  }));
  return [{ key: 'all', label: 'ทั้งหมด', desc: 'ดูทุกประเภทกิจกรรม', icon: 'explore', value: '' }, ...cats];
});

const steps = computed(() => [
  { id: 'type', title: 'อยากไปแนวไหน?', subtitle: 'เลือกประเภทกิจกรรมที่สนใจ', options: typeOptions.value },
  { id: 'difficulty', title: 'คุณเป็นสายไหน?', subtitle: 'บอกระดับความท้าทายที่ชอบ', options: difficultyOptions },
  { id: 'days', title: 'มีเวลากี่วัน?', subtitle: 'เลือกความยาวของทริป', options: dayOptions },
]);

const selectionSummary = computed(() => {
  const parts = [];
  if (answers.typeLabel) parts.push(answers.typeLabel);
  if (answers.difficultyLabel) parts.push(answers.difficultyLabel);
  if (answers.days?.label) parts.push(answers.days.label);
  return parts.length ? `ตามที่เลือก: ${parts.join(' · ')}` : '';
});

function choose(opt) {
  const id = steps.value[step.value].id;
  if (id === 'type') { answers.type = opt.value; answers.typeLabel = opt.value ? opt.label : ''; }
  else if (id === 'difficulty') { answers.difficulty = opt.value; answers.difficultyLabel = opt.value ? opt.label : ''; }
  else if (id === 'days') { answers.days = (opt.min_days || opt.max_days) ? { label: opt.label, min_days: opt.min_days, max_days: opt.max_days } : null; }
  advance();
}

function skip() {
  const id = steps.value[step.value].id;
  if (id === 'type') { answers.type = ''; answers.typeLabel = ''; }
  else if (id === 'difficulty') { answers.difficulty = ''; answers.difficultyLabel = ''; }
  else if (id === 'days') { answers.days = null; }
  advance();
}

function advance() {
  if (step.value < steps.value.length - 1) {
    transitionName.value = 'slide-next';
    step.value++;
  } else {
    computeResults();
  }
}

function back() {
  transitionName.value = 'slide-prev';
  if (step.value > 0) step.value--;
}

function daysParams() {
  if (!answers.days) return {};
  const p = {};
  if (answers.days.min_days) p.min_days = answers.days.min_days;
  if (answers.days.max_days) p.max_days = answers.days.max_days;
  return p;
}

async function computeResults() {
  phase.value = 'results';
  loading.value = true;
  relaxedNote.value = '';
  try {
    const dp = daysParams();
    let trips = await tripsStore.findTrips({ type: answers.type, difficulty: answers.difficulty, ...dp });

    // progressive relaxation so the quiz never ends on a dead end
    if (!trips.length && (dp.min_days || dp.max_days)) {
      trips = await tripsStore.findTrips({ type: answers.type, difficulty: answers.difficulty });
      if (trips.length) relaxedNote.value = `ยังไม่มีทริป "${answers.days.label}" ตอนนี้ — นี่คือทริปใกล้เคียงที่น่าสนใจ`;
    }
    if (!trips.length && answers.difficulty) {
      trips = await tripsStore.findTrips({ type: answers.type });
      if (trips.length) relaxedNote.value = `ยังไม่มีทริประดับ "${answers.difficultyLabel}" ตามที่เลือก — ลองดูตัวเลือกใกล้เคียงนี้`;
    }
    results.value = trips;
  } catch (e) {
    results.value = [];
  } finally {
    loading.value = false;
  }
}

function viewAll() {
  const dp = daysParams();
  tripsStore.filters.type = answers.type || '';
  tripsStore.filters.difficulty = answers.difficulty || '';
  tripsStore.filters.min_days = dp.min_days || '';
  tripsStore.filters.max_days = dp.max_days || '';
  router.push({ name: 'trips' });
}

function restart() {
  phase.value = 'quiz';
  step.value = 0;
  transitionName.value = 'slide-prev';
  answers.type = ''; answers.typeLabel = '';
  answers.difficulty = ''; answers.difficultyLabel = '';
  answers.days = null;
  results.value = [];
  relaxedNote.value = '';
}

onMounted(() => categoriesStore.fetchCategories());
</script>

<style scoped>
.slide-next-enter-active, .slide-next-leave-active,
.slide-prev-enter-active, .slide-prev-leave-active {
  transition: opacity 0.3s ease, transform 0.3s cubic-bezier(0.22, 1, 0.36, 1);
}
.slide-next-enter-from { opacity: 0; transform: translateX(24px); }
.slide-next-leave-to { opacity: 0; transform: translateX(-24px); }
.slide-prev-enter-from { opacity: 0; transform: translateX(-24px); }
.slide-prev-leave-to { opacity: 0; transform: translateX(24px); }

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(30px); }
  to { opacity: 1; transform: translateY(0); }
}
.animate-fade-in-up {
  animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
  opacity: 0;
}
</style>
