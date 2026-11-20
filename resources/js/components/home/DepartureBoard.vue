<template>
  <!-- ตารางรอบที่จะออกเดินทางเร็ว ๆ นี้ — คนจองทริปถามเรื่องวันก่อนเรื่องอื่น
       รวมรอบที่ยังเปิดรับและยังมีที่ว่างจากทุกทริป เรียงตามวันออกเดินทาง -->
  <section v-if="loading || rows.length" class="bg-white py-16 md:py-20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <SectionTitle
        align="left"
        class="mb-8 md:mb-10"
        :title="$t('ออกเดินทางเร็ว ๆ นี้')"
        :subtitle="$t('รอบที่ยังเปิดรับ เรียงตามวันเดินทาง พร้อมที่นั่งว่างตอนนี้')"
      >
        <template #action>
          <router-link to="/calendar" class="section-more">
            {{ $t('ดูปฏิทินทริปทั้งหมด') }}
            <span class="material-symbols-rounded icon-18">chevron_right</span>
          </router-link>
        </template>
      </SectionTitle>

      <div v-if="loading" class="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <div v-for="i in 6" :key="i" class="h-[104px] rounded-xl bg-[var(--color-sand)] animate-pulse"></div>
      </div>

      <ul v-else class="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <li v-for="row in rows" :key="row.schedule.id" class="min-w-0">
          <router-link :to="row.link" class="dep-row group">
            <!-- วันที่ออกเดินทาง -->
            <span class="dep-date" :class="{ 'dep-date--soon': row.daysLeft <= 7 }">
              <span class="text-[12px] font-semibold opacity-80">{{ row.weekday }}</span>
              <span class="text-[26px] font-extrabold leading-none tabular-nums">{{ row.day }}</span>
              <span class="text-[12.5px] font-bold">{{ row.month }}</span>
            </span>

            <span class="dep-thumb">
              <img v-if="row.image" :src="row.image" alt="" loading="lazy" class="w-full h-full object-cover" />
            </span>

            <span class="min-w-0 flex-1">
              <span class="block truncate text-[15.5px] font-bold text-[var(--color-text-dark)] group-hover:text-[var(--color-accent)] transition-colors">
                {{ row.trip.title }}
              </span>
              <span class="mt-0.5 block truncate text-[13px] text-[var(--color-text-muted)]">
                {{ row.meta }}
              </span>
              <span class="mt-2 flex items-center gap-2">
                <span class="dep-bar" aria-hidden="true">
                  <span :style="{ width: `${row.fillPct}%` }" :class="row.seatsLeft <= 2 ? 'bg-amber-500' : 'bg-[var(--color-accent-light)]'"></span>
                </span>
                <span class="text-[12.5px] font-semibold whitespace-nowrap" :class="row.seatsLeft <= 2 ? 'text-amber-700' : 'text-[var(--color-text-mid)]'">
                  {{ $t('ว่าง {seatsLeft} ที่', { seatsLeft: row.seatsLeft }) }}
                </span>
                <span class="sm:hidden ml-auto text-[14px] font-extrabold text-[var(--color-text-dark)] tabular-nums">{{ $t('฿{price}', { price: row.price }) }}</span>
              </span>
            </span>

            <span class="hidden sm:flex flex-col items-end shrink-0 pl-2">
              <span class="text-[12px] text-[var(--color-text-muted)]">{{ $t('เริ่มต้น') }}</span>
              <span class="text-[19px] font-extrabold text-[var(--color-text-dark)] tabular-nums">{{ $t('฿{price}', { price: row.price }) }}</span>
              <span class="dep-cta">{{ $t('ดูรอบนี้') }}</span>
            </span>
          </router-link>
        </li>
      </ul>
    </div>
  </section>
</template>

<script setup>
import { $t } from '@/i18n';
import { DATE_LOCALE } from '@/i18n';
import { computed } from 'vue';
import SectionTitle from '../SectionTitle.vue';
import { tripCardImage } from '../../lib/tripImage';
import { bangkokToday, daysUntil } from '../../lib/bangkokDate';

const props = defineProps({
  trips: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  limit: { type: Number, default: 8 },
});

const WEEKDAYS = [$t('อา.'), $t('จ.'), $t('อ.'), $t('พ.'), $t('พฤ.'), $t('ศ.'), $t('ส.')];
const MONTHS = [$t('ม.ค.'), $t('ก.พ.'), $t('มี.ค.'), $t('เม.ย.'), $t('พ.ค.'), $t('มิ.ย.'), $t('ก.ค.'), $t('ส.ค.'), $t('ก.ย.'), $t('ต.ค.'), $t('พ.ย.'), $t('ธ.ค.')];

// ราคาต่ำสุดของรอบ — จุดขึ้นรถแต่ละจุดตั้งราคาเองได้ จึงดูจากจุดรับก่อน
function schedulePrice(schedule) {
  const prices = (schedule.pickup_points || []).map((p) => Number(p.price)).filter((n) => n > 0);
  if (prices.length) return Math.min(...prices);
  return Number(schedule.price) || 0;
}

const rows = computed(() => {
  const today = bangkokToday();
  const out = [];

  for (const trip of props.trips) {
    for (const s of trip.schedules || []) {
      const seats = Number(s.bookable_seats ?? s.available_seats ?? 0);
      if (s.status !== 'open' || !s.departure_date || s.departure_date < today || seats <= 0 || s.is_charter) continue;
      out.push({ trip, schedule: s, seats });
    }
  }

  return out
    .sort((a, b) => a.schedule.departure_date.localeCompare(b.schedule.departure_date))
    .slice(0, props.limit)
    .map(({ trip, schedule, seats }) => {
      // วันที่จาก API เป็นวันไทยอยู่แล้ว — อ่านเป็นเที่ยงวัน UTC กันวันเลื่อนตาม timezone ของเครื่อง
      const d = new Date(`${schedule.departure_date}T12:00:00Z`);
      const total = Number(schedule.total_seats) || seats;
      const days = Number(trip.duration_days) || 1;
      const price = schedulePrice(schedule);

      return {
        trip,
        schedule,
        link: { path: `/trips/${trip.slug}`, query: { schedule: schedule.id } },
        weekday: WEEKDAYS[d.getUTCDay()],
        day: d.getUTCDate(),
        month: MONTHS[d.getUTCMonth()],
        daysLeft: daysUntil(schedule.departure_date) ?? 99,
        image: tripCardImage(trip),
        meta: [trip.country_label || trip.location, days > 1 ? $t('{days} วัน {v} คืน', { days, v: days - 1 }) : $t('1 วัน')].filter(Boolean).join(' · '),
        seatsLeft: seats,
        fillPct: total ? Math.min(100, Math.round(((total - seats) / total) * 100)) : 0,
        price: price ? price.toLocaleString(DATE_LOCALE) : Number(trip.min_price || 0).toLocaleString(DATE_LOCALE),
      };
    });
});
</script>

<style scoped>
.dep-row {
  display: flex;
  align-items: center;
  gap: 0.875rem;
  padding: 0.75rem;
  border-radius: 0.875rem;
  border: 1px solid var(--color-sand-dark);
  background-color: #fff;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}
.dep-row:hover {
  border-color: color-mix(in srgb, var(--color-accent) 40%, transparent);
  background-color: color-mix(in srgb, var(--color-accent-tint) 50%, #fff);
}
.dep-date {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.125rem;
  width: 4rem;
  height: 4.75rem;
  flex-shrink: 0;
  border-radius: 0.625rem;
  background-color: var(--color-sand);
  color: var(--color-text-dark);
}
.dep-date--soon {
  background-color: var(--color-accent);
  color: #fff;
}
.dep-thumb {
  display: none;
  width: 4.75rem;
  height: 4.75rem;
  flex-shrink: 0;
  overflow: hidden;
  border-radius: 0.625rem;
  background-color: var(--color-sand-dark);
}
@media (min-width: 640px) {
  .dep-thumb { display: block; }
}
.dep-bar {
  position: relative;
  display: block;
  width: 5.5rem;
  height: 0.375rem;
  overflow: hidden;
  border-radius: 9999px;
  background-color: var(--color-sand-dark);
}
.dep-bar > span {
  position: absolute;
  inset: 0 auto 0 0;
  border-radius: 9999px;
}
.dep-cta {
  margin-top: 0.375rem;
  padding: 0.25rem 0.75rem;
  border-radius: 9999px;
  background-color: var(--color-accent);
  color: #fff;
  font-size: 12.5px;
  font-weight: 700;
  transition: background-color 0.2s ease;
}
.dep-row:hover .dep-cta {
  background-color: var(--color-accent-mid);
}
</style>
