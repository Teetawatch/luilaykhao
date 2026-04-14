import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useWishlistStore = defineStore('wishlist', () => {
    const favorites = ref([]);
    const lastAdded = ref(null);
    let toastTimer = null;

    const toggleFavorite = (trip) => {
        const tripId = typeof trip === 'object' ? trip.id : trip;
        const index = favorites.value.findIndex(f => (typeof f === 'object' ? f.id : f) === tripId);
        if (index === -1) {
            const tripObj = typeof trip === 'object' ? trip : { id: tripId };
            favorites.value.push(tripObj);
            lastAdded.value = tripObj;
            if (toastTimer) clearTimeout(toastTimer);
            toastTimer = setTimeout(() => { lastAdded.value = null; }, 3000);
        } else {
            favorites.value.splice(index, 1);
            lastAdded.value = null;
        }
        saveToLocalStorage();
    };

    const isFavorite = (tripId) => {
        return favorites.value.some(f => (typeof f === 'object' ? f.id : f) === tripId);
    };

    const saveToLocalStorage = () => {
        localStorage.setItem('wishlist', JSON.stringify(favorites.value));
    };

    const loadFromLocalStorage = () => {
        const stored = localStorage.getItem('wishlist');
        if (stored) {
            try {
                favorites.value = JSON.parse(stored);
            } catch (e) {
                console.error('Failed to parse wishlist from localStorage', e);
                favorites.value = [];
            }
        }
    };

    // Initial load
    loadFromLocalStorage();

    return {
        favorites,
        lastAdded,
        toggleFavorite,
        isFavorite
    };
});
