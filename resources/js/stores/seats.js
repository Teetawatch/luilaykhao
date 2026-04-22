import { defineStore } from 'pinia';
import api from '../lib/axios';

const MAX_BOOKING_SECONDS = 10 * 60; // 10 minutes hard limit
const SESSION_KEY_PREFIX = 'booking_session';
// Master index key tracks which scoped session is currently active
const ACTIVE_SESSION_INDEX_KEY = 'booking_session_active';

function buildSessionKey(scheduleId, region) {
  let key = `${SESSION_KEY_PREFIX}_${scheduleId || 'unknown'}`;
  if (region) key += `_${region}`;
  return key;
}

function saveActiveIndex(scheduleId, region) {
  sessionStorage.setItem(ACTIVE_SESSION_INDEX_KEY, JSON.stringify({ scheduleId, region }));
}

function loadActiveIndex() {
  try {
    const raw = sessionStorage.getItem(ACTIVE_SESSION_INDEX_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

function clearActiveIndex() {
  sessionStorage.removeItem(ACTIVE_SESSION_INDEX_KEY);
}

function saveSession(lockExpiry, activeBookingInfo, selectedSeats) {
  if (lockExpiry && activeBookingInfo) {
    const scheduleId = activeBookingInfo.scheduleId;
    const region = activeBookingInfo.region;
    const key = buildSessionKey(scheduleId, region);
    sessionStorage.setItem(key, JSON.stringify({ lockExpiry, activeBookingInfo, selectedSeats: selectedSeats ?? [] }));
    saveActiveIndex(scheduleId, region);
  } else {
    // Clear the currently active session
    const idx = loadActiveIndex();
    if (idx) {
      sessionStorage.removeItem(buildSessionKey(idx.scheduleId, idx.region));
    }
    clearActiveIndex();
  }
}

function loadSession() {
  try {
    const idx = loadActiveIndex();
    if (!idx) return null;
    const key = buildSessionKey(idx.scheduleId, idx.region);
    const raw = sessionStorage.getItem(key);
    if (!raw) {
      clearActiveIndex();
      return null;
    }
    const data = JSON.parse(raw);
    // Check if expiry is still in the future
    if (data.lockExpiry && new Date(data.lockExpiry) > new Date()) {
      return data;
    }
    sessionStorage.removeItem(key);
    clearActiveIndex();
    return null;
  } catch {
    return null;
  }
}

const _saved = loadSession();

export const useSeatsStore = defineStore('seats', {
  state: () => ({
    seatMap: null,
    selectedSeats: _saved?.selectedSeats ?? [],
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
          saveSession(this.lockExpiry, this.activeBookingInfo, this.selectedSeats);
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
      saveSession(this.lockExpiry, this.activeBookingInfo, this.selectedSeats);
    },

    saveStep(step) {
      if (this.activeBookingInfo) {
        this.activeBookingInfo = { ...this.activeBookingInfo, step };
        saveSession(this.lockExpiry, this.activeBookingInfo, this.selectedSeats);
      }
    },

    restoreCountdown() {
      if (this.lockExpiry && this.activeBookingInfo) {
        this.startCountdown();
      }
    },

    pauseSession() {
      // Stop in-memory countdown but keep sessionStorage so it can resume after re-login
      this.stopCountdown();
      this.activeBookingInfo = null;
      this.lockExpiry = null;
      this._onExpireCallbacks = [];
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
      saveSession(this.lockExpiry, this.activeBookingInfo, this.selectedSeats);
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

    /**
     * Clear any session that does NOT match the given scheduleId+region.
     * This prevents stale data from a different booking context from interfering.
     */
    clearIfMismatch(scheduleId, region) {
      const idx = loadActiveIndex();
      if (!idx) return false;
      const sameSchedule = String(idx.scheduleId) === String(scheduleId);
      const sameRegion = (idx.region || null) === (region || null);
      if (!sameSchedule || !sameRegion) {
        this.clearSelection();
        return true; // was cleared
      }
      return false; // matched, not cleared
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

    startManualCountdown(tripTitle, scheduleId, region = null) {
      this.stopCountdown();
      this.lockExpiry = new Date(Date.now() + MAX_BOOKING_SECONDS * 1000).toISOString();
      this.activeBookingInfo = { tripTitle, scheduleId, region, startedAt: Date.now() };
      saveSession(this.lockExpiry, this.activeBookingInfo, this.selectedSeats);
      this.startCountdown();
    },
  },
});
