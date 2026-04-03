import { defineStore } from 'pinia';
import api from '../lib/axios';

export const useBookingStore = defineStore('booking', {
  state: () => ({
    currentBooking: null,
    bookings: [],
    loading: false,
    meta: null,
  }),

  actions: {
    async createBooking(data) {
      this.loading = true;
      try {
        const res = await api.post('/bookings', data);
        this.currentBooking = res.data.data;
        return res.data;
      } finally {
        this.loading = false;
      }
    },

    async fetchBooking(ref) {
      this.loading = true;
      try {
        const res = await api.get(`/bookings/${ref}`);
        this.currentBooking = res.data.data;
        return this.currentBooking;
      } finally {
        this.loading = false;
      }
    },

    async fetchMyBookings(page = 1) {
      this.loading = true;
      try {
        const res = await api.get('/bookings', { params: { page } });
        this.bookings = res.data.data;
        this.meta = res.data.meta;
      } finally {
        this.loading = false;
      }
    },

    async cancelBooking(ref, reason) {
      this.loading = true;
      try {
        const res = await api.post(`/bookings/${ref}/cancel`, { reason });
        return res.data;
      } finally {
        this.loading = false;
      }
    },

    async chargePayment(data) {
      this.loading = true;
      try {
        const res = await api.post('/payments/charge', data);
        return res.data;
      } finally {
        this.loading = false;
      }
    },
  },
});
