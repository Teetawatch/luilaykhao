<template>
  <!-- "ทริปนี้ไหวไหม" — เทียบความหนักของทริปกับที่ผู้ใช้เคยเดินมา (ฉบับเว็บของการ์ดเดียวกันในแอป) -->
  <div v-if="data && data.reason !== 'trip_data_missing'" class="rounded-[1.5rem] border border-gray-100 bg-[var(--color-sand)]/60 p-5 md:p-6">
    <div class="mb-4 flex items-center gap-2.5">
      <span class="material-symbols-rounded text-[22px] text-[var(--color-accent)]">monitor_heart</span>
      <div>
        <p class="text-base font-extrabold leading-tight text-[var(--color-text-dark)]">ทริปนี้ไหวไหม</p>
        <p class="text-xs font-bold text-[var(--color-text-muted)]">เทียบกับที่คุณเคยเดินมา</p>
      </div>
    </div>

    <!-- ผลการเทียบ -->
    <template v-if="data.available">
      <div class="flex items-start gap-2.5 rounded-xl px-4 py-3" :class="verdict.bg">
        <span class="material-symbols-rounded text-[22px]" :class="verdict.text">{{ verdict.icon }}</span>
        <div>
          <p class="text-[15px] font-extrabold" :class="verdict.text">{{ verdict.label }}</p>
          <p class="mt-0.5 text-[13px] font-semibold leading-relaxed text-[var(--color-text-mid)]">{{ data.message }}</p>
        </div>
      </div>

      <div class="mt-5 space-y-5">
        <div v-for="row in compareRows" :key="row.label">
          <div class="mb-2 flex items-baseline justify-between">
            <span class="text-[13px] font-bold text-[var(--color-text-dark)]">{{ row.label }}</span>
            <span v-if="row.ratio != null" class="text-[12px] font-extrabold tabular-nums" :class="verdict.text">{{ fmt(row.ratio) }} เท่า</span>
          </div>
          <div class="space-y-1.5">
            <div class="flex items-center gap-3">
              <span class="w-20 shrink-0 text-[12px] font-semibold text-[var(--color-text-muted)]">ทริปนี้</span>
              <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-200/70">
                <div class="h-full rounded-full" :class="verdict.bar" :style="{ width: `${row.tripPct}%` }"></div>
              </div>
              <span class="w-16 shrink-0 text-right text-[12px] font-bold tabular-nums text-[var(--color-text-dark)]">{{ fmt(row.trip) }} {{ row.unit }}</span>
            </div>
            <div class="flex items-center gap-3">
              <span class="w-20 shrink-0 text-[12px] font-semibold text-[var(--color-text-muted)]">คุณเคยทำได้</span>
              <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-200/70">
                <div class="h-full rounded-full bg-gray-400/60" :style="{ width: `${row.youPct}%` }"></div>
              </div>
              <span class="w-16 shrink-0 text-right text-[12px] font-bold tabular-nums text-[var(--color-text-dark)]">
                {{ row.you > 0 ? `${fmt(row.you)} ${row.unit}` : '—' }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <p class="mt-4 text-[11px] font-semibold text-[var(--color-text-muted)]">
        {{ data.source === 'history' ? `เทียบจากทริปที่คุณเดินจบมาแล้ว ${data.you?.trips_count || 0} ทริป` : 'เทียบจากข้อมูลที่คุณกรอกไว้เอง' }}
      </p>

      <div v-if="data.alternatives?.length" class="mt-5 border-t border-gray-200 pt-4">
        <p class="mb-2.5 text-[14px] font-extrabold text-[var(--color-text-dark)]">ลองทริปที่เบากว่านี้ก่อนไหม</p>
        <router-link
          v-for="alt in data.alternatives"
          :key="alt.id"
          :to="`/trips/${alt.slug}`"
          class="flex items-center gap-3 rounded-xl px-2 py-2 transition hover:bg-white"
        >
          <img v-if="alt.cover_image" :src="alt.cover_image" :alt="alt.title" loading="lazy" class="h-11 w-11 shrink-0 rounded-lg object-cover" />
          <div class="min-w-0 flex-1">
            <p class="truncate text-[13px] font-bold text-[var(--color-text-dark)]">{{ alt.title }}</p>
            <p class="text-[11px] font-semibold text-[var(--color-text-muted)] tabular-nums">
              <template v-if="alt.distance_km">{{ fmt(alt.distance_km) }} กม.</template>
              <template v-if="alt.distance_km && alt.elevation_gain_m"> · </template>
              <template v-if="alt.elevation_gain_m">ไต่ {{ Number(alt.elevation_gain_m).toLocaleString() }} ม.</template>
            </p>
          </div>
          <span class="material-symbols-rounded text-[18px] text-[var(--color-text-muted)]">chevron_right</span>
        </router-link>
      </div>
    </template>

    <!-- ยังไม่มีประวัติ — ให้กรอกคร่าว ๆ เอง -->
    <template v-else-if="data.reason === 'no_baseline'">
      <p class="text-[13px] font-semibold leading-relaxed text-[var(--color-text-mid)]">{{ data.message }}</p>
      <form class="mt-4" @submit.prevent="saveBaseline">
        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="mb-1 block text-[12px] font-bold text-[var(--color-text-muted)]">เคยเดินไกลสุด (กม.)</span>
            <input v-model="distance" type="number" inputmode="decimal" min="0" max="1000" step="0.1"
              class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-[15px] font-bold text-[var(--color-text-dark)] focus:border-[var(--color-accent)] focus:outline-none" />
          </label>
          <label class="block">
            <span class="mb-1 block text-[12px] font-bold text-[var(--color-text-muted)]">เคยไต่สูงสุด (ม.)</span>
            <input v-model="elevation" type="number" inputmode="numeric" min="0" max="9000" step="1"
              class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-[15px] font-bold text-[var(--color-text-dark)] focus:border-[var(--color-accent)] focus:outline-none" />
          </label>
        </div>
        <p v-if="error" class="mt-2 text-[12px] font-bold text-red-600">{{ error }}</p>
        <button type="submit" :disabled="saving"
          class="mt-3 w-full rounded-full bg-[var(--color-accent)] py-3 text-[15px] font-extrabold text-white transition hover:bg-[var(--color-accent-mid)] disabled:opacity-50">
          {{ saving ? 'กำลังบันทึก...' : 'ดูว่าทริปนี้ไหวไหม' }}
        </button>
        <p class="mt-2 text-[11px] font-semibold text-[var(--color-text-muted)]">ไม่แน่ใจก็กรอกคร่าว ๆ ได้ ใช้แค่เทียบให้ดูเท่านั้น</p>
      </form>
    </template>

    <!-- ยังไม่ได้ล็อกอิน -->
    <template v-else-if="data.reason === 'not_logged_in'">
      <p class="text-[13px] font-semibold leading-relaxed text-[var(--color-text-mid)]">{{ data.message }}</p>
      <router-link
        :to="{ name: 'login', query: { redirect: `${route.fullPath.split('#')[0]}#overview` } }"
        class="mt-3 inline-flex items-center gap-1.5 rounded-full border border-[var(--color-accent)]/25 bg-white px-5 py-2.5 text-[14px] font-extrabold text-[var(--color-accent)] transition hover:bg-[var(--color-accent)] hover:text-white"
      >
        <span class="material-symbols-rounded text-[18px]">login</span>
        เข้าสู่ระบบเพื่อเช็ก
      </router-link>
    </template>

    <p v-else class="text-[13px] font-semibold text-[var(--color-text-mid)]">{{ data.message }}</p>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useRoute } from 'vue-router';
import api from '../lib/axios';

const props = defineProps({
  slug: { type: String, required: true },
});

const route = useRoute();
const data = ref(null);
const distance = ref('');
const elevation = ref('');
const saving = ref(false);
const error = ref('');

const VERDICTS = {
  comfortable: { label: 'น่าจะไปได้สบาย', icon: 'check_circle', text: 'text-[var(--color-accent)]', bg: 'bg-[var(--color-accent)]/10', bar: 'bg-[var(--color-accent)]' },
  stretch: { label: 'ท้าทายอยู่บ้าง', icon: 'trending_up', text: 'text-amber-700', bg: 'bg-amber-100/70', bar: 'bg-amber-500' },
  beyond: { label: 'หนักกว่าที่เคยเดินมาพอสมควร', icon: 'warning', text: 'text-red-600', bg: 'bg-red-50', bar: 'bg-red-500' },
};
const verdict = computed(() => VERDICTS[data.value?.verdict] || VERDICTS.comfortable);

/** แถบเทียบ — สเกลจากค่าที่มากกว่า ให้สองแถบเทียบกันตรง ๆ */
const compareRows = computed(() => {
  const d = data.value;
  if (!d?.available) return [];
  const rows = [
    { label: 'ระยะทาง', trip: Number(d.trip?.distance_km) || 0, you: Number(d.you?.max_distance_km) || 0, unit: 'กม.', ratio: d.comparison?.distance_ratio },
    { label: 'ความสูงสะสม', trip: Number(d.trip?.elevation_gain_m) || 0, you: Number(d.you?.max_elevation_gain_m) || 0, unit: 'ม.', ratio: d.comparison?.elevation_ratio },
  ];
  return rows
    .filter((r) => r.trip > 0)
    .map((r) => {
      const max = Math.max(r.trip, r.you);
      return { ...r, tripPct: (r.trip / max) * 100, youPct: (r.you / max) * 100 };
    });
});

function fmt(v) {
  const n = Number(v);
  return Number.isInteger(n) ? n.toLocaleString() : n.toLocaleString(undefined, { maximumFractionDigits: 1 });
}

async function load() {
  try {
    const res = await api.get(`/trips/${props.slug}/readiness`);
    data.value = res.data.data;
  } catch {
    // ไม่ใช่ข้อมูลหลักของหน้า — ถ้าโหลดไม่ได้ก็ไม่ต้องแสดง
    data.value = null;
  }
}

async function saveBaseline() {
  const d = parseFloat(distance.value);
  const e = parseInt(elevation.value, 10);
  if (!(d > 0) && !(e > 0)) {
    error.value = 'กรอกอย่างน้อยหนึ่งช่องนะครับ';
    return;
  }
  error.value = '';
  saving.value = true;
  try {
    await api.post('/me/hiking-baseline', {
      max_distance_km: d > 0 ? d : null,
      max_elevation_gain_m: e > 0 ? e : null,
    });
    await load();
  } catch (err) {
    error.value = err.response?.data?.message || 'บันทึกไม่สำเร็จ ลองใหม่อีกครั้ง';
  } finally {
    saving.value = false;
  }
}

watch(() => props.slug, load, { immediate: true });
</script>
