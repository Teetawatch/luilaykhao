import { defineStore } from 'pinia';
import { ref, watch } from 'vue';
import api from '../lib/axios';
import { useToast } from '../lib/toast';
import { createWishlistSync } from '../lib/wishlistSync';
import { useAuthStore } from './auth';

/**
 * ทริปที่ถูกใจ — แขกเก็บในเบราว์เซอร์ ล็อกอินแล้วเก็บกับบัญชี (ใช้ร่วมกับแอป)
 * ตรรกะทั้งหมดอยู่ที่ lib/wishlistSync.js ตรงนี้แค่ห่อเป็นสถานะของ Vue
 */
export const useWishlistStore = defineStore('wishlist', () => {
  const sync = createWishlistSync({
    storage: window.localStorage,
    http: {
      get: async (url) => (await api.get(url)).data?.data,
      post: async (url, body) => (await api.post(url, body)).data?.data,
      delete: async (url) => (await api.delete(url)).data?.data,
    },
  });

  const favorites = ref(sync.items);
  const lastAdded = ref(null);
  /** การ์ดแจ้งที่เพิ่งเพิ่มบอกด้วยว่าเปิดแจ้งเตือนรอบใหม่/ราคาลดให้แล้ว */
  const lastAddedAlertOn = ref(false);
  let toastTimer = null;

  sync.subscribe((items) => {
    favorites.value = items;
  });

  // ผูกบัญชีตามสถานะล็อกอิน — ล็อกอินแล้วแต่ยังไม่รู้ว่าใคร (โปรไฟล์ยังไม่มา) รอก่อน
  const auth = useAuthStore();
  watch(
    () => (auth.isLoggedIn ? auth.user?.id ?? undefined : null),
    (accountId) => {
      if (accountId === undefined) return;
      sync.bind(accountId);
    },
    { immediate: true },
  );

  // หัวใจที่กดจากแอป/เครื่องอื่นระหว่างที่แท็บนี้อยู่เบื้องหลัง (มีตัวหน่วงในตัว)
  if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') sync.refresh();
    });
  }

  const toggleFavorite = async (trip) => {
    let result;
    try {
      result = await sync.toggle(trip);
    } catch (error) {
      useToast().error(error.message);
      return;
    }
    if (!result.changed) return;

    if (result.favourite) {
      const id = typeof trip === 'object' ? trip.id : trip;
      lastAdded.value = sync.items.find((t) => t.id === id) ?? (typeof trip === 'object' ? trip : { id });
      lastAddedAlertOn.value = result.alertOn;
      if (toastTimer) clearTimeout(toastTimer);
      toastTimer = setTimeout(() => {
        lastAdded.value = null;
      }, 3000);
    } else {
      lastAdded.value = null;
    }
  };

  // อ่านจาก ref — เทมเพลตที่เรียกตัวนี้ต้องวาดใหม่ตอนหัวใจเปลี่ยน
  const isFavorite = (tripId) =>
    favorites.value.some((t) => (typeof t === 'object' ? t.id : t) === tripId);

  return {
    favorites,
    lastAdded,
    lastAddedAlertOn,
    toggleFavorite,
    isFavorite,
    refresh: () => sync.refresh({ force: true }),
  };
});
