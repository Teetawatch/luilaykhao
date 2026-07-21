<template>
  <div class="min-h-screen bg-[var(--color-sand)]/40">
    <div class="max-w-2xl mx-auto px-4 py-8 sm:py-12">

      <header class="mb-8">
        <h1 class="text-2xl sm:text-3xl font-black text-[var(--color-text-dark)] tracking-tight">
          ถามหาทริปที่ใช่
        </h1>
        <p class="text-[var(--color-text-muted)] mt-2">
          พิมพ์แบบที่คิดในหัวได้เลย เช่น งบเท่าไหร่ ไปกี่วัน ไหวแค่ไหน แล้วเราจะหาทริปที่เปิดจองอยู่จริงมาให้
        </p>
      </header>

      <!-- คำถามตัวอย่าง — ลดแรงเสียดทานของช่องเปล่า -->
      <div v-if="!turns.length" class="flex flex-wrap gap-2 mb-8">
        <button
          v-for="example in examples"
          :key="example"
          type="button"
          @click="send(example)"
          class="px-4 py-2.5 rounded-2xl bg-white border border-gray-200 text-sm font-medium text-left hover:border-[var(--color-primary)] transition-colors"
        >
          {{ example }}
        </button>
      </div>

      <!-- บทสนทนา -->
      <div class="space-y-6 mb-8">
        <div v-for="(turn, i) in turns" :key="i">
          <div v-if="turn.role === 'user'" class="flex justify-end">
            <p class="max-w-[85%] bg-[var(--color-primary)] text-white rounded-2xl rounded-br-md px-4 py-3 font-medium">
              {{ turn.content }}
            </p>
          </div>

          <div v-else>
            <p class="bg-white border border-gray-200 rounded-2xl rounded-bl-md px-4 py-3 whitespace-pre-line">
              {{ turn.content }}
            </p>

            <div v-if="turn.trips?.length" class="mt-3 space-y-2">
              <router-link
                v-for="trip in turn.trips"
                :key="trip.slug"
                :to="`/trips/${trip.slug}`"
                class="flex items-center gap-3 bg-white border border-gray-200 rounded-2xl p-3 hover:border-[var(--color-primary)] transition-colors"
              >
                <div class="min-w-0 flex-1">
                  <p class="font-bold text-[var(--color-text-dark)] truncate">{{ trip.title }}</p>
                  <p class="text-sm text-[var(--color-text-muted)] truncate">
                    {{ trip.location }}<span v-if="trip.duration_days"> · {{ trip.duration_days }} วัน</span>
                    <span v-if="trip.next_departure_label"> · รอบถัดไป {{ trip.next_departure_label }}</span>
                  </p>
                </div>
                <p class="font-black text-[var(--color-text-dark)] shrink-0">
                  ฿{{ Number(trip.price_from).toLocaleString('th-TH') }}
                </p>
              </router-link>
            </div>
          </div>
        </div>

        <p v-if="loading" class="text-[var(--color-text-muted)] font-medium">กำลังหาทริปให้...</p>
        <p v-if="error" class="text-red-600 font-medium">{{ error }}</p>
      </div>

      <!-- ช่องพิมพ์ -->
      <form @submit.prevent="send(draft)" class="sticky bottom-4 flex gap-2">
        <input
          v-model="draft"
          type="text"
          maxlength="500"
          placeholder="อยากไปแบบไหน..."
          class="flex-1 px-5 py-3.5 rounded-2xl border border-gray-200 bg-white outline-none focus:border-[var(--color-primary)] font-medium"
        >
        <button
          type="submit"
          :disabled="loading || !draft.trim()"
          class="px-6 py-3.5 rounded-2xl bg-[var(--color-primary)] text-white font-bold disabled:opacity-50 shrink-0"
        >
          ถาม
        </button>
      </form>

      <p class="text-xs text-[var(--color-text-muted)] text-center mt-4">
        คำตอบมาจาก AI อาจคลาดเคลื่อนได้ กรุณาตรวจสอบรายละเอียดในหน้าทริปอีกครั้งก่อนจอง
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import api from '../lib/axios';

const examples = [
  'งบ 3000 ไปเหนือ 2 วัน มือใหม่',
  'อยากไปทะเลเสาร์อาทิตย์นี้',
  'ทริปเดินป่าที่ไม่โหดมาก มีไหม',
];

const turns = ref([]);
const draft = ref('');
const loading = ref(false);
const error = ref('');

async function send(text) {
  const message = (text || '').trim();
  if (!message || loading.value) return;

  // ส่งเฉพาะข้อความล้วนเป็นบริบท — การ์ดทริปเป็นเรื่องของหน้าเว็บ ไม่ใช่ของโมเดล
  const history = turns.value.map(t => ({ role: t.role, content: t.content }));

  turns.value.push({ role: 'user', content: message });
  draft.value = '';
  error.value = '';
  loading.value = true;

  try {
    const res = await api.post('/concierge', { message, history });
    const data = res.data?.data || {};
    turns.value.push({ role: 'assistant', content: data.reply || '', trips: data.trips || [] });
  } catch (e) {
    error.value = e?.response?.data?.message || 'ตอนนี้ผู้ช่วยตอบไม่ได้ ลองใหม่อีกครั้งในสักครู่';
    // คำถามที่ล้มเหลวไม่ควรค้างอยู่ในบทสนทนา ไม่งั้นบริบทรอบหน้าจะเพี้ยน
    turns.value.pop();
    draft.value = message;
  } finally {
    loading.value = false;
  }
}
</script>
