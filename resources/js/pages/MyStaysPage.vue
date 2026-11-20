<template>
  <div class="font-anuphan bg-[var(--color-sand)] min-h-screen pb-24">
    <PageHeader
      :title="$t('ที่พักของฉัน')"
      :subtitle="$t('ใบจองที่พักชุมชนทั้งหมด')"
      :crumbs="[{ to: '/profile', label: $t('บัญชีของฉัน') }]"
      container="max-w-3xl px-4 sm:px-6"
    >
      <template #actions>
        <router-link to="/stays" class="ph-btn ph-btn--solid">
          <span class="material-symbols-rounded icon-18">cottage</span> {{ $t('หาที่พัก') }} </router-link>
      </template>
    </PageHeader>
    <div class="max-w-3xl mx-auto px-4 sm:px-6 pt-2">

      <div v-if="loading" class="mt-6 space-y-3">
        <div v-for="i in 3" :key="i" class="h-28 rounded-3xl shimmer"></div>
      </div>

      <div v-else-if="!items.length" class="mt-8 rounded-3xl bg-white border border-[var(--color-sand-dark)] px-6 py-14 text-center">
        <span class="material-symbols-rounded icon-32 text-[var(--color-accent)]">cottage</span>
        <p class="mt-3 font-extrabold text-[var(--color-text-dark)]">{{ $t('ยังไม่มีการจองที่พัก') }}</p>
        <p class="mt-1 text-sm text-[var(--color-text-muted)]">{{ $t('พักคืนก่อนหรือหลังทริปกับชุมชนใกล้ ๆ ได้เลย') }}</p>
      </div>

      <div v-else class="mt-6 grid gap-3">
        <router-link v-for="b in items" :key="b.ref" :to="`/stays/bookings/${b.ref}`" class="item press">
          <img v-if="b.stay?.cover_image" :src="b.stay.cover_image" :alt="b.stay.name" class="item__img" loading="lazy" />
          <div class="min-w-0 flex-1">
            <span class="status" :class="'status--' + tone(b.status)">{{ b.status_label }}</span>
            <p class="mt-1 truncate font-extrabold text-[var(--color-text-dark)]">{{ b.stay?.name }}</p>
            <p class="text-[13px] font-semibold text-[var(--color-text-muted)]">{{ $t('{range} · {guests} คน', { range: range(b), guests: b.guests }) }}</p>
            <p v-if="b.can_pay" class="text-[13px] font-bold text-amber-700">{{ $t('รอชำระ ฿{Number}', { Number: Number(b.total_amount).toLocaleString($dateLocale) }) }}</p>
            <p v-else-if="b.can_review" class="text-[13px] font-bold text-[var(--color-accent)]">{{ $t('รีวิวที่พักนี้') }}</p>
          </div>
          <span class="material-symbols-rounded text-[var(--color-text-muted)]">chevron_right</span>
        </router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { $t } from '@/i18n';
import PageHeader from '../components/PageHeader.vue';
import { onMounted, ref } from 'vue';
import api from '../lib/axios';

const MONTHS = [$t('ม.ค.'), $t('ก.พ.'), $t('มี.ค.'), $t('เม.ย.'), $t('พ.ค.'), $t('มิ.ย.'), $t('ก.ค.'), $t('ส.ค.'), $t('ก.ย.'), $t('ต.ค.'), $t('พ.ย.'), $t('ธ.ค.')];
const loading = ref(true);
const items = ref([]);

function short(ymd) {
  const [y, m, d] = ymd.split('-').map(Number);
  return `${d} ${MONTHS[m - 1]} ${String(y + 543).slice(2)}`;
}

function range(b) {
  return `${short(b.check_in)} – ${short(b.check_out)}`;
}

function tone(s) {
  if (['confirmed', 'completed'].includes(s)) return 'good';
  if (['declined', 'expired', 'cancelled'].includes(s)) return 'bad';
  return 'wait';
}

onMounted(async () => {
  try {
    const res = await api.get('/stay-bookings');
    items.value = res.data.data || [];
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.item { display: flex; align-items: center; gap: 0.9rem; padding: 0.9rem; border-radius: 1.25rem; background: #fff; border: 1px solid var(--color-sand-dark); }
.item__img { width: 84px; height: 84px; border-radius: 1rem; object-fit: cover; flex-shrink: 0; }
.status { display: inline-flex; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 12px; font-weight: 800; }
.status--good { background: #dcfce7; color: #166534; }
.status--wait { background: #fef3c7; color: #92400e; }
.status--bad { background: #fee2e2; color: #991b1b; }
</style>
