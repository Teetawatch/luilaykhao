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

    /**
     * เรียกโดยไม่ส่งอะไร = ได้การจองทั้งหมดในครั้งเดียว (MyReviewsPage ใช้แบบนี้)
     * ส่ง perPage มาด้วยจึงจะเปลี่ยนเป็นแบบแบ่งหน้า — ให้ตรงกับฝั่ง API ที่คงพฤติกรรม
     * เดิมไว้เป็นค่าเริ่มต้นเพื่อไม่ให้แอปมือถือที่ปล่อยไปแล้วได้แค่หน้าแรก
     */
    async fetchMyBookings({ page = 1, perPage = null, scope = null } = {}) {
      this.loading = true;
      try {
        const params = { page };
        if (perPage) params.per_page = perPage;
        if (scope) params.scope = scope;

        const res = await api.get('/bookings', { params });
        this.bookings = res.data.data;
        this.meta = res.data.meta ?? null;
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
        const isFormData = data instanceof FormData;
        const res = await api.post('/payments/charge', data, {
          headers: isFormData ? { 'Content-Type': 'multipart/form-data' } : {},
        });
        return res.data;
      } finally {
        this.loading = false;
      }
    },

    async scanSlip(formData) {
      const res = await api.post('/payments/scan-slip', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      return res.data;
    },

    async chargeInstallment(data) {
      this.loading = true;
      try {
        const res = await api.post('/payments/charge-installment', data);
        return res.data;
      } finally {
        this.loading = false;
      }
    },
  },
});
