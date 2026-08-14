import { defineStore } from 'pinia';
import api from '../lib/axios';

export const useTripsStore = defineStore('trips', {
  state: () => ({
    trips: [],
    currentTrip: null,
    schedules: [],
    loading: false,
    meta: null,
    destinations: null,
    filters: {
      type: '',
      // '' = ทุกที่, 'domestic' = ในประเทศ, 'international' = ต่างประเทศ
      destination: '',
      // ปลายทางย่อยใต้แท็บด้านบน — ภาคไทยคู่กับ domestic, รหัสประเทศคู่กับ international
      region: '',
      country: '',
      difficulty: '',
      search: '',
      date: '',
      min_days: '',
      max_days: '',
      sort: 'popular',
    },
  }),

  actions: {
    async fetchTrips(page = 1) {
      this.loading = true;
      try {
        const params = { page, per_page: 12 };
        if (this.filters.type) params.type = this.filters.type;
        if (this.filters.destination) params.destination = this.filters.destination;
        if (this.filters.region) params.region = this.filters.region;
        if (this.filters.country) params.country = this.filters.country;
        if (this.filters.difficulty) params.difficulty = this.filters.difficulty;
        if (this.filters.search) params.search = this.filters.search;
        if (this.filters.date) params.date = this.filters.date;
        if (this.filters.min_days) params.min_days = this.filters.min_days;
        if (this.filters.max_days) params.max_days = this.filters.max_days;
        // ส่งไปให้ backend เรียง เพื่อให้การเรียงครอบคลุมทุกหน้า ไม่ใช่แค่หน้าที่กำลังดู
        if (this.filters.sort) params.sort = this.filters.sort;

        const res = await api.get('/trips', { params });
        this.trips = res.data.data;
        this.meta = res.data.meta;
      } finally {
        this.loading = false;
      }
    },

    // One-off lookup for the Trip Finder quiz — returns trips without touching
    // the listing state. Pass { type, difficulty, min_days, max_days }.
    async findTrips(params = {}) {
      const clean = { per_page: 12 };
      for (const [k, v] of Object.entries(params)) {
        if (v !== '' && v != null) clean[k] = v;
      }
      const res = await api.get('/trips', { params: clean });
      return res.data.data;
    },

    // ภาค/ประเทศที่มีทริปอยู่จริงพร้อมจำนวน — เติมแถบเลือกปลายทางหน้ารวมทริป
    async fetchDestinations() {
      const res = await api.get('/trips/destinations');
      this.destinations = res.data.data;
      return this.destinations;
    },

    // Trips with an open upcoming round that's almost full (powers the home rail).
    async fetchAlmostFull() {
      const res = await api.get('/trips/almost-full');
      return res.data.data;
    },

    // Payload for the entry popup: flash-sale + almost-full trips, plus the
    // admin-controlled enabled flag/title.
    async fetchUrgentPopup() {
      const res = await api.get('/trips/urgent-popup');
      return res.data.data;
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
      this.filters = { type: '', destination: '', region: '', country: '', difficulty: '', search: '', date: '', min_days: '', max_days: '', sort: 'popular' };
    },
  },
});
