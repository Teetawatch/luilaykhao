<template>
  <div class="min-h-screen bg-[var(--color-sand)] pb-32">
    <PageHeader
      :title="$t('ทริปเดือน{p}', { p: data.month_label || '' })"
      :crumb-label="$t('ปฏิทินทริป')"
      :subtitle="$t('ทุกรอบที่เปิดจองในเดือนนี้ พร้อมวันว่างและราคา กดเข้าไปจองได้เลย')"
      container="max-w-4xl px-4 sm:px-6"
    >
      <template #actions>
        <button type="button" class="ph-btn ph-btn--solid" @click="share">
          <span class="material-symbols-rounded icon-18">ios_share</span> {{ $t('ส่งลิงก์นี้ต่อ') }} </button>
        <button type="button" class="ph-btn" @click="copyText">
          <span class="material-symbols-rounded icon-18">content_copy</span> {{ $t('ก๊อปรายการเป็นข้อความ') }} </button>
      </template>
    </PageHeader>
    <div class="max-w-4xl mx-auto px-4 sm:px-6 pt-8">

      <!-- เลือกเดือน -->
      <div class="flex gap-2 overflow-x-auto scrollbar-none pb-1 mb-6">
        <router-link
          v-for="month in data.months"
          :key="month.value"
          :to="`/calendar/${month.value}`"
          class="cal-month"
          :class="month.is_selected ? 'cal-month--on' : ''"
        >
          {{ month.is_current ? $t('เดือนนี้') : month.label }}
        </router-link>
      </div>

      <!-- กำลังโหลด -->
      <div v-if="loading" class="space-y-3">
        <div v-for="i in 4" :key="i" class="bg-white rounded-[20px] border border-[#E8EEEF] p-4 animate-pulse">
          <div class="h-3 w-24 bg-[#EDF1F1] rounded mb-3"></div>
          <div class="h-4 w-2/3 bg-[#EDF1F1] rounded mb-2"></div>
          <div class="h-3 w-1/3 bg-[#EDF1F1] rounded"></div>
        </div>
      </div>

      <template v-else>
        <!-- ทริปไฟไหม้ -->
        <section v-if="data.hot?.length" class="mb-8">
          <div class="flex items-baseline gap-2 mb-1">
            <h2 class="text-lg font-bold text-[#1a1c1c]">{{ $t('🔥 ทริปไฟไหม้') }}</h2>
            <span class="text-xs text-[#505E5E]">{{ $t('ออกเดินทางใน {hot_days} วัน · ยังมีที่ว่าง', { hot_days: data.hot_days }) }}</span>
          </div>
          <p class="text-[#505E5E] text-xs mb-3">{{ $t('ที่นั่งเหลือน้อยและใกล้วันเดินทาง ตัดสินใจไวได้เปรียบครับ') }}</p>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <router-link
              v-for="round in data.hot"
              :key="`hot-${round.id}`"
              :to="round.url"
              class="bg-white rounded-[20px] border border-[#FFD9A8] overflow-hidden flex"
            >
              <div class="w-[92px] shrink-0 bg-[#EDF1F1]">
                <img
                  v-if="round.trip.cover_image"
                  :src="round.trip.cover_image"
                  :alt="round.trip.title"
                  class="w-full h-full object-cover"
                  loading="lazy"
                >
              </div>
              <div class="p-3 min-w-0 flex-1">
                <div class="flex items-center gap-1.5 mb-1">
                  <span class="cal-flag">{{ round.days_left_label }}</span>
                  <span v-if="round.on_flash_sale" class="cal-flag cal-flag--sale">{{ $t('ลดพิเศษ') }}</span>
                </div>
                <p class="font-bold text-[#1a1c1c] text-sm leading-snug truncate">{{ round.trip.title }}</p>
                <p class="text-[11.5px] text-[#505E5E] mt-0.5 truncate">{{ round.date_label }}</p>
                <p class="mt-1.5 text-sm">
                  <span class="font-extrabold text-[var(--color-accent)]">{{ baht(round.price) }}</span>
                  <span v-if="round.original_price" class="text-[11.5px] text-[#96A5A5] line-through ml-1.5">
                    {{ baht(round.original_price) }}
                  </span>
                  <span class="text-[11.5px] text-[#B45309] font-bold ml-1.5">{{ round.status_label }}</span>
                </p>
              </div>
            </router-link>
          </div>
        </section>

        <!-- ไม่มีรอบในเดือนนี้ -->
        <div v-if="!data.days?.length" class="bg-white rounded-[20px] border border-[#E8EEEF] p-8 text-center">
          <span class="material-symbols-rounded text-[44px] text-[#B4C4C4]">calendar_month</span>
          <p class="text-[#1a1c1c] font-bold mt-3 mb-1">{{ $t('เดือนนี้ยังไม่มีรอบเปิดจอง') }}</p>
          <p class="text-[#505E5E] text-sm mb-4">{{ $t('ลองดูเดือนถัดไป หรือทักทีมงานให้ช่วยจัดรอบให้ได้เลยครับ') }}</p>
          <router-link to="/trips" class="cal-action cal-action--solid inline-flex">{{ $t('ดูทริปทั้งหมด') }}</router-link>
        </div>

        <!-- รายวัน -->
        <section v-else>
          <div class="flex items-baseline justify-between mb-3">
            <h2 class="text-lg font-bold text-[#1a1c1c]">{{ $t('รอบเดินทางเดือน{month_label}', { month_label: data.month_label }) }}</h2>
            <span class="text-xs text-[#505E5E]">
              {{ $t('{trip_count} ทริป · {round_count} รอบ', { trip_count: data.summary.trip_count, round_count: data.summary.round_count }) }} </span>
          </div>

          <div class="space-y-3">
            <div
              v-for="day in data.days"
              :key="day.date"
              class="bg-white rounded-[20px] border border-[#E8EEEF] overflow-hidden"
            >
              <div class="flex items-center gap-2 px-4 pt-3.5 pb-1">
                <span class="text-[13px] font-extrabold text-[var(--color-accent)]">{{ day.date_label }}</span>
                <span class="text-[11.5px] text-[#96A5A5]">{{ day.weekday }}</span>
              </div>

              <router-link
                v-for="round in day.rounds"
                :key="round.id"
                :to="round.url"
                class="flex items-center gap-3 px-4 py-3 border-t border-[#F1F5F4] first:border-t-0"
              >
                <div class="w-12 h-12 rounded-[14px] bg-[#EDF1F1] shrink-0 overflow-hidden">
                  <img
                    v-if="round.trip.cover_image"
                    :src="round.trip.cover_image"
                    :alt="round.trip.title"
                    class="w-full h-full object-cover"
                    loading="lazy"
                  >
                </div>

                <div class="min-w-0 flex-1">
                  <p class="font-bold text-[#1a1c1c] text-sm leading-snug truncate">{{ round.trip.title }}</p>
                  <p class="text-[11.5px] text-[#505E5E] mt-0.5 truncate">
                    {{ round.date_label }}
                    <template v-if="round.trip.duration_days"> {{ $t('· {duration_days} วัน', { duration_days: round.trip.duration_days }) }}</template>
                    <template v-if="round.trip.location"> · {{ round.trip.location }}</template>
                  </p>
                  <p class="text-[11.5px] mt-0.5 font-bold" :class="round.is_full ? 'text-[#96A5A5]' : (round.is_low ? 'text-[#B45309]' : 'text-[#5C7C78]')">
                    {{ round.status_label }}
                    <span v-if="round.on_flash_sale" class="text-[#B45309]"> {{ $t('· ลดพิเศษ') }}</span>
                  </p>
                </div>

                <div class="text-right shrink-0">
                  <p class="font-extrabold text-[var(--color-accent)] text-sm">{{ baht(round.price) }}</p>
                  <p v-if="round.original_price" class="text-[11px] text-[#96A5A5] line-through">
                    {{ baht(round.original_price) }}
                  </p>
                </div>
              </router-link>
            </div>
          </div>

          <p v-if="data.summary.min_price" class="text-center text-[#505E5E] text-xs mt-5 leading-relaxed"> {{ $t('เดือนนี้ราคาเริ่มต้น {baht} · ยังว่างรวม {seats_left} ที่', { baht: baht(data.summary.min_price), seats_left: data.summary.seats_left }) }}<br>
            <!-- ราคาจุดขึ้นรถเป็นราคาเต็มของจุดนั้น ไม่ใช่ส่วนต่าง จึงต่างจากราคารอบได้ -->
            <span class="text-[#96A5A5]">{{ $t('ราคาที่แสดงเป็นราคาของรอบ อาจต่างกันตามจุดขึ้นรถที่เลือก') }}</span>
          </p>
        </section>
      </template>

      <div class="mt-8 bg-white rounded-[20px] border border-[#E8EEEF] p-5 text-center">
        <p class="text-[#1a1c1c] font-bold mb-1">{{ $t('ไม่เจอวันที่สะดวก?') }}</p>
        <p class="text-[#505E5E] text-sm mb-4">{{ $t('บอกวันที่ไหวกับจำนวนคน เดี๋ยวทีมงานช่วยจัดรอบให้ครับ') }}</p>
        <div class="flex flex-wrap gap-2 justify-center">
          <router-link to="/trips" class="cal-action">{{ $t('ดูทริปทั้งหมด') }}</router-link>
          <router-link to="/contact" class="cal-action cal-action--solid">{{ $t('ทักทีมงาน') }}</router-link>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { $t } from '@/i18n';
import { DATE_LOCALE } from '@/i18n';
import PageHeader from '../components/PageHeader.vue';
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useHead } from '@unhead/vue';
import api from '../lib/axios';
import { useToast } from '../lib/toast';

const route = useRoute();
const toast = useToast();

const loading = ref(true);
const data = ref({ months: [], days: [], hot: [], summary: {}, month_label: '' });

// หัวเรื่องของหน้านี้เปลี่ยนตามเดือน จึงตั้งเองแทนที่จะใช้ meta คงที่ของ router
useHead({
  title: computed(() => (data.value.month_label
    ? $t('ทริปเดือน{month_label} | รอบที่เปิดจองทั้งหมด', { month_label: data.value.month_label })
    : $t('ปฏิทินทริป | เดือนนี้เดือนหน้ามีทริปอะไรบ้าง'))),
});

function baht(amount) {
  return `฿${Number(amount || 0).toLocaleString(DATE_LOCALE)}`;
}

const shareUrl = computed(() => `${window.location.origin}/calendar/${data.value.month || ''}`);

/**
 * ข้อความสำหรับวางในไลน์ — ทีมงานตอบคำถาม "เดือนหน้ามีทริปอะไร" วันละหลายครั้ง
 * และบางคนถามมาในแชทที่แปะลิงก์แล้วไม่มีใครกด ข้อความจึงต้องอ่านจบได้ในตัวมันเอง
 */
function calendarText() {
  const lines = [$t('ทริปเดือน{month_label} 🏕', { month_label: data.value.month_label }), ''];

  (data.value.days || []).forEach((day) => {
    day.rounds.forEach((round) => {
      const parts = [`• ${round.date_label} · ${round.trip.title}`];
      if (round.trip.duration_days) parts.push($t('{duration_days} วัน', { duration_days: round.trip.duration_days }));
      parts.push(baht(round.price));
      parts.push(round.status_label);
      lines.push(parts.join(' · '));
    });
  });

  if (!(data.value.days || []).length) lines.push($t('ยังไม่มีรอบเปิดจองในเดือนนี้ครับ'));

  lines.push('', $t('ดูรายละเอียดและจองได้ที่ {shareUrl}', { shareUrl: shareUrl.value }));

  return lines.join('\n');
}

async function copyText() {
  try {
    await navigator.clipboard.writeText(calendarText());
    toast.success($t('ก๊อปข้อความแล้ว วางในไลน์ได้เลย'));
  } catch {
    toast.error($t('ก๊อปไม่สำเร็จ ลองเลือกข้อความเองอีกทีนะครับ'));
  }
}

async function share() {
  const payload = {
    title: $t('ทริปเดือน{month_label} | ลุยเลเขา', { month_label: data.value.month_label }),
    text: $t('ทริปเดือน{month_label} ของลุยเลเขา ดูวันว่างและจองได้เลย', { month_label: data.value.month_label }),
    url: shareUrl.value,
  };

  if (navigator.share) {
    try {
      await navigator.share(payload);
      return;
    } catch { /* ผู้ใช้ยกเลิก */ }
  }

  try {
    await navigator.clipboard.writeText(shareUrl.value);
    toast.success($t('ก๊อปลิงก์แล้ว ส่งต่อได้เลย'));
  } catch {
    toast.error($t('ก๊อปลิงก์ไม่สำเร็จ ลองกดแชร์จากเบราว์เซอร์แทนนะครับ'));
  }
}

async function load() {
  loading.value = true;
  try {
    const res = await api.get('/trips/calendar', {
      params: { month: route.params.month || undefined },
    });
    data.value = res.data?.data || data.value;
  } finally {
    loading.value = false;
  }
}

watch(() => route.params.month, load, { immediate: true });
</script>

<style scoped>
.cal-action {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border-radius: 999px;
  border: 1px solid #E8EEEF;
  background: #fff;
  padding: 9px 16px;
  font-size: 13px;
  font-weight: 700;
  color: #1a1c1c;
}
.cal-action--solid {
  background: var(--color-accent);
  border-color: var(--color-accent);
  color: #fff;
}
.cal-month {
  flex-shrink: 0;
  border-radius: 999px;
  border: 1px solid #E8EEEF;
  background: #fff;
  padding: 8px 16px;
  font-size: 13px;
  font-weight: 700;
  color: #505E5E;
}
.cal-month--on {
  background: var(--color-accent);
  border-color: var(--color-accent);
  color: #fff;
}
.cal-flag {
  display: inline-block;
  border-radius: 999px;
  background: #FFF3E2;
  color: #B45309;
  font-size: 10.5px;
  font-weight: 800;
  padding: 2px 8px;
}
.cal-flag--sale {
  background: #FDECEC;
  color: #B91C1C;
}
</style>
