import { defineStore } from 'pinia';
import api from '../lib/axios';

const MAX_BOOKING_SECONDS = 10 * 60; // 10 minutes hard limit
const SESSION_KEY = 'booking_session';

function saveSession(lockExpiry, activeBookingInfo) {
  if (lockExpiry && activeBookingInfo) {
    sessionStorage.setItem(SESSION_KEY, JSON.stringify({ lockExpiry, activeBookingInfo }));
  } else {
    sessionStorage.removeItem(SESSION_KEY);
  }
}

function loadSession() {
  try {
    const raw = sessionStorage.getItem(SESSION_KEY);
    if (!raw) return null;
    const data = JSON.parse(raw);
    // Check if expiry is still in the future
    if (data.lockExpiry && new Date(data.lockExpiry) > new Date()) {
      return data;
    }
    sessionStorage.removeItem(SESSION_KEY);
    return null;
  } catch {
    return null;
  }
}

const _saved = loadSession();

export const useSeatsStore = defineStore('seats', {
  state: () => ({
    seatMap: null,
    selectedSeats: [],
    loading: false,
    lockExpiry: _saved?.lockExpiry ?? null,
    countdownSeconds: 0,
    countdownTimer: null,
    activeBookingInfo: _saved?.activeBookingInfo ?? null,
    _onExpireCallbacks: [],
  }),

  getters: {
    hasSelectedSeats: (state) => state.selectedSeats.length > 0,
    selectedSeatIds: (state) => state.selectedSeats.map(s => s.id),
    hasActiveBooking: (state) => state.activeBookingInfo !== null && state.countdownSeconds > 0,
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
          // Enforce 10-minute hard limit regardless of server expiry
          const serverExpiry = res.data.data.expires_at ? new Date(res.data.data.expires_at) : null;
          const maxExpiry = new Date(Date.now() + MAX_BOOKING_SECONDS * 1000);
          this.lockExpiry = serverExpiry && serverExpiry < maxExpiry ? serverExpiry.toISOString() : maxExpiry.toISOString();
          saveSession(this.lockExpiry, this.activeBookingInfo);
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

    setActiveBookingInfo(info) {
      this.activeBookingInfo = info;
      saveSession(this.lockExpiry, this.activeBookingInfo);
    },

    saveStep(step) {
      if (this.activeBookingInfo) {
        this.activeBookingInfo = { ...this.activeBookingInfo, step };
        saveSession(this.lockExpiry, this.activeBookingInfo);
      }
    },

    restoreCountdown() {
      if (this.lockExpiry && this.activeBookingInfo) {
        this.startCountdown();
      }
    },

    onExpire(callback) {
      this._onExpireCallbacks.push(callback);
    },

    offExpire(callback) {
      this._onExpireCallbacks = this._onExpireCallbacks.filter(cb => cb !== callback);
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
      this.activeBookingInfo = null;
      this._onExpireCallbacks = [];
      saveSession(null, null);
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
          const callbacks = [...this._onExpireCallbacks];
          callbacks.forEach(cb => cb());
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

    startManualCountdown(tripTitle, scheduleId) {
      this.stopCountdown();
      this.lockExpiry = new Date(Date.now() + MAX_BOOKING_SECONDS * 1000).toISOString();
      this.activeBookingInfo = { tripTitle, scheduleId, startedAt: Date.now() };
      saveSession(this.lockExpiry, this.activeBookingInfo);
      this.startCountdown();
    },
  },
});
