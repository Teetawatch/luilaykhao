import { defineStore } from 'pinia';
import api from '../lib/axios';

export const useSeatsStore = defineStore('seats', {
  state: () => ({
    seatMap: null,
    selectedSeats: [],
    loading: false,
    lockExpiry: null,
    countdownSeconds: 0,
    countdownTimer: null,
  }),

  getters: {
    hasSelectedSeats: (state) => state.selectedSeats.length > 0,
    selectedSeatIds: (state) => state.selectedSeats.map(s => s.id),
  },

  actions: {
    async fetchSeatMap(scheduleId) {
      this.loading = true;
      try {
        const res = await api.get(`/schedules/${scheduleId}/seats`);
        this.seatMap = res.data.data;
        return this.seatMap;
      } finally {
        this.loading = false;
      }
    },

    async lockSeats(scheduleId, seatIds) {
      try {
        const res = await api.post(`/schedules/${scheduleId}/seats/lock`, {
          seat_ids: seatIds,
        });
        if (res.data.data?.locked) {
          this.lockExpiry = res.data.data.expires_at;
          this.startCountdown();
        }
        return res.data;
      } catch (err) {
        throw err.response?.data || err;
      }
    },

    async unlockSeats(scheduleId) {
      if (this.selectedSeats.length === 0) return;
      try {
        await api.delete(`/schedules/${scheduleId}/seats/lock`, {
          data: { seat_ids: this.selectedSeatIds },
        });
      } catch {}
      this.clearSelection();
    },

    toggleSeat(seat) {
      const idx = this.selectedSeats.findIndex(s => s.id === seat.id);
      if (idx >= 0) {
        this.selectedSeats.splice(idx, 1);
      } else {
        this.selectedSeats.push(seat);
      }
    },

    updateSeatStatus(seatId, status) {
      if (!this.seatMap?.seats) return;
      const seat = this.seatMap.seats.find(s => s.id === seatId);
      if (seat) seat.status = status;
    },

    clearSelection() {
      this.selectedSeats = [];
      this.lockExpiry = null;
      this.stopCountdown();
    },

    startCountdown() {
      this.stopCountdown();
      if (!this.lockExpiry) return;

      const updateCountdown = () => {
        const diff = Math.floor((new Date(this.lockExpiry) - Date.now()) / 1000);
        this.countdownSeconds = Math.max(0, diff);
        if (this.countdownSeconds <= 0) {
          this.stopCountdown();
        }
      };

      updateCountdown();
      this.countdownTimer = setInterval(updateCountdown, 1000);
    },

    stopCountdown() {
      if (this.countdownTimer) {
        clearInterval(this.countdownTimer);
        this.countdownTimer = null;
      }
      this.countdownSeconds = 0;
    },
  },
});
