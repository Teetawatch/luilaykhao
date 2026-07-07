<template>
  <!-- พยากรณ์อากาศวันเดินทาง — แสดงบนหน้าทริปเมื่อ backend แนบ weather มากับ schedule -->
  <div
    v-if="weather"
    class="flex items-center gap-3 p-4 rounded-[1.25rem] border"
    :class="severityClass">
    <div class="text-3xl leading-none shrink-0">{{ emoji }}</div>
    <div class="min-w-0 flex-1">
      <div class="flex items-center gap-2 flex-wrap">
        <span class="text-sm font-black text-[var(--color-text-dark)]">{{ weather.description_th || 'พยากรณ์อากาศ' }}</span>
        <span v-if="tempText" class="text-xs font-bold text-[var(--color-text-muted)]">{{ tempText }}</span>
      </div>
      <div class="flex items-center gap-3 mt-0.5 text-[11px] font-bold text-[var(--color-text-muted)]">
        <span v-if="popText" class="flex items-center gap-1">
          <span class="material-symbols-rounded text-[13px]">water_drop</span>{{ popText }}
        </span>
        <span v-if="windText" class="flex items-center gap-1">
          <span class="material-symbols-rounded text-[13px]">air</span>{{ windText }}
        </span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  weather: { type: Object, default: null },
});

// จับกลุ่มตามรหัส OpenWeather (2xx ฝนฟ้าคะนอง, 3xx/5xx ฝน, 6xx หิมะ, 7xx หมอก, 800 แจ่มใส, 80x เมฆ)
const emoji = computed(() => {
  const code = Number(props.weather?.condition_code) || 0;
  if (code >= 200 && code < 300) return '⛈️';
  if (code >= 300 && code < 600) return '🌧️';
  if (code >= 600 && code < 700) return '🌨️';
  if (code >= 700 && code < 800) return '🌫️';
  if (code === 800) return '☀️';
  if (code > 800) return '⛅';
  return '🌤️';
});

const tempText = computed(() => {
  const min = props.weather?.temp_min;
  const max = props.weather?.temp_max;
  if (min == null && max == null) return '';
  if (min != null && max != null) return `${Math.round(min)}–${Math.round(max)}°C`;
  return `${Math.round(max ?? min)}°C`;
});

const popText = computed(() => {
  const pop = props.weather?.pop;
  if (pop == null) return '';
  return `โอกาสฝน ${Math.round(pop * (pop <= 1 ? 100 : 1))}%`;
});

const windText = computed(() => {
  const wind = props.weather?.wind_speed;
  if (wind == null) return '';
  return `ลม ${Math.round(wind)} กม./ชม.`;
});

const severityClass = computed(() => {
  switch (props.weather?.severity) {
    case 'danger':
      return 'bg-red-50 border-red-200';
    case 'warning':
      return 'bg-amber-50 border-amber-200';
    default:
      return 'bg-sky-50 border-sky-100';
  }
});
</script>
