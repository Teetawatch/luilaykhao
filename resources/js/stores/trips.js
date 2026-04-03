import { defineStore } from 'pinia';
import api from '../lib/axios';

export const useTripsStore = defineStore('trips', {
  state: () => ({
    trips: [],
    currentTrip: null,
    schedules: [],
    loading: false,
    meta: null,
    filters: {
      type: '',
      difficulty: '',
      search: '',
    },
  }),

  actions: {
    async fetchTrips(page = 1) {
      this.loading = true;
      try {
        const params = { page, per_page: 12 };
        if (this.filters.type) params.type = this.filters.type;
        if (this.filters.difficulty) params.difficulty = this.filters.difficulty;
        if (this.filters.search) params.search = this.filters.search;

        const res = await api.get('/trips', { params });
        this.trips = res.data.data;
        this.meta = res.data.meta;
      } finally {
        this.loading = false;
      }
    },

    async fetchTrip(slug) {
      this.loading = true;
      try {
        const res = await api.get(`/trips/${slug}`);
        this.currentTrip = res.data.data;
        return this.currentTrip;
      } finally {
        this.loading = false;
      }
    },

    async fetchSchedules(slug) {
      const res = await api.get(`/trips/${slug}/schedules`);
      this.schedules = res.data.data;
      return this.schedules;
    },

    setFilter(key, value) {
      this.filters[key] = value;
    },

    clearFilters() {
      this.filters = { type: '', difficulty: '', search: '' };
    },
  },
});
