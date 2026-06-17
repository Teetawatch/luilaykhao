<template>
  <Teleport to="body">
    <Transition name="scarcity-pop">
      <div v-if="visible && trip"
        class="fixed bottom-6 right-6 z-[200] w-[330px] max-w-[calc(100vw-2rem)] bg-white rounded-[1.5rem] shadow-[0_20px_50px_rgba(0,0,0,0.18)] border border-gray-100 overflow-hidden">
        <!-- cover -->
        <router-link :to="`/trips/${trip.slug}`" @click="goBook" class="block relative h-32">
          <img v-if="trip.thumbnail_image || trip.cover_image" :src="trip.thumbnail_image || trip.cover_image" :alt="trip.title"
            class="w-full h-full object-cover" />
          <div v-else class="w-full h-full bg-gray-100"></div>
          <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></div>
          <span class="absolute top-3 left-3 inline-flex items-center gap-1 px-3 py-1.5 rounded-full text-xs font-black shadow-lg"
            :class="level === 'last' ? 'bg-red-500 text-white animate-pulse' : 'bg-amber-400 text-amber-950'">
            <span class="material-symbols-rounded text-[14px]">local_fire_department</span>
            {{ label }}
          </span>
        </router-link>

        <!-- close -->
        <button @click="close" aria-label="ปิด"
          class="absolute top-3 right-3 w-8 h-8 rounded-full bg-black/30 hover:bg-black/50 text-white flex items-center justify-center backdrop-blur-md transition-colors">
          <span class="material-symbols-rounded text-[18px]">close</span>
        </button>

        <!-- body -->
        <div class="p-4">
          <p class="text-[11px] font-black uppercase tracking-wider text-[var(--color-accent)] mb-1">ทริปนี้กำลังจะเต็ม</p>
          <h4 class="font-extrabold text-[var(--color-text-dark)] leading-snug line-clamp-2 mb-3">{{ trip.title }}</h4>
          <router-link :to="`/trips/${trip.slug}`" @click="goBook"
            class="flex items-center justify-center gap-2 w-full bg-[var(--color-primary)] text-white py-2.5 rounded-full text-sm font-extrabold hover:bg-[var(--color-accent)] transition-colors">
            <span class="material-symbols-rounded text-[18px]">bolt</span>
            จองเลย
          </router-link>
          <button @click="dontShowAgain"
            class="block w-full text-center text-[11px] text-gray-400 hover:text-gray-600 font-bold mt-2 transition-colors">
            ไม่ต้องแสดงอีก
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useRoute } from 'vue-router';
import { useTripsStore } from '../stores/trips';
import { useWishlistStore } from '../stores/wishlist';
import { tripScarcityLabel, tripScarcityLevel } from '../lib/scheduleHelpers';

const DISMISS_KEY = 'llk_scarcity_dismissed'; // localStorage — permanent
const SESSION_KEY = 'llk_scarcity_shown';     // sessionStorage — once per tab session
const SHOW_DELAY_MS = 6000;

const route = useRoute();
const tripsStore = useTripsStore();
const wishlist = useWishlistStore();

const visible = ref(false);
const trip = ref(null);
const label = ref('');
const level = ref('soon');
let timer = null;

function safeGet(storage, key) {
  try { return storage.getItem(key); } catch { return null; }
}
function safeSet(storage, key, val) {
  try { storage.setItem(key, val); } catch { /* ignore */ }
}

async function maybeShow() {
  if (safeGet(localStorage, DISMISS_KEY) === '1') return;
  if (safeGet(sessionStorage, SESSION_KEY) === '1') return;

  let trips = [];
  try {
    trips = await tripsStore.fetchAlmostFull();
  } catch {
    return;
  }
  if (!trips || !trips.length) return;

  // Prefer a trip the user already showed interest in (wishlist), else most urgent.
  const picked = trips.find((t) => wishlist.isFavorite(t.id)) || trips[0];
  if (!picked) return;

  // Don't nag while the user is already on that trip's page.
  if (route.path === `/trips/${picked.slug}`) return;

  trip.value = picked;
  label.value = tripScarcityLabel(picked) || 'ใกล้เต็มแล้ว';
  level.value = tripScarcityLevel(picked) || 'soon';
  visible.value = true;
  safeSet(sessionStorage, SESSION_KEY, '1');
}

function close() {
  visible.value = false;
}
function goBook() {
  visible.value = false;
}
function dontShowAgain() {
  safeSet(localStorage, DISMISS_KEY, '1');
  visible.value = false;
}

onMounted(() => {
  timer = setTimeout(maybeShow, SHOW_DELAY_MS);
});
onUnmounted(() => {
  if (timer) clearTimeout(timer);
});
</script>

<style scoped>
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.scarcity-pop-enter-active {
  transition: opacity 0.4s ease, transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
}
.scarcity-pop-leave-active {
  transition: opacity 0.25s ease, transform 0.25s ease;
}
.scarcity-pop-enter-from,
.scarcity-pop-leave-to {
  opacity: 0;
  transform: translateY(20px) scale(0.96);
}
</style>
