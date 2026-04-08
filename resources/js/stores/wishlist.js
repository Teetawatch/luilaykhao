import { defineStore } from 'pinia';
import { ref, onMounted } from 'vue';

export const useWishlistStore = defineStore('wishlist', () => {
    const favorites = ref([]);

    const toggleFavorite = (tripId) => {
        const index = favorites.value.indexOf(tripId);
        if (index === -1) {
            favorites.value.push(tripId);
        } else {
            favorites.value.splice(index, 1);
        }
        saveToLocalStorage();
    };

    const isFavorite = (tripId) => {
        return favorites.value.includes(tripId);
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
        toggleFavorite,
        isFavorite
    };
});
