import { defineStore } from 'pinia';
import api from '../lib/axios';

const BASE_BOOKING_SECONDS = 10 * 60; // 10 minutes base
const SECONDS_PER_ADDITIONAL_PASSENGER = 5 * 60; // 5 minutes per extra person
const SESSION_KEY_PREFIX = 'booking_session';

function calculateDuration(count) {
  const pCount = Math.max(1, count || 1);
  return BASE_BOOKING_SECONDS + (pCount - 1) * SECONDS_PER_ADDITIONAL_PASSENGER;
}

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

function saveSession(lockExpiry, activeBookingInfo, selectedSeats, windowTotalSeconds = null) {
  if (lockExpiry && activeBookingInfo) {
    const scheduleId = activeBookingInfo.scheduleId;
    const region = activeBookingInfo.region;
    const key = buildSessionKey(scheduleId, region);
    sessionStorage.setItem(key, JSON.stringify({
      lockExpiry,
      activeBookingInfo,
      selectedSeats: selectedSeats ?? [],
      windowTotalSeconds,
    }));
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
    // ความยาวเต็มของช่วงเวลาที่กำลังนับถอยหลังอยู่ (วินาที) — มาจากเส้นตายจริงที่
    // เซิร์ฟเวอร์ให้มา ไม่ใช่สูตรที่ฝั่งเว็บเดาเอง ใช้วาดแถบความคืบหน้าให้ตรงกับนาฬิกา
    windowTotalSeconds: _saved?.windowTotalSeconds ?? null,
    countdownSeconds: 0,
    countdownTimer: null,
    activeBookingInfo: _saved?.activeBookingInfo ?? null,
    // ที่นั่งที่หน้านี้ล็อกไว้กับเซิร์ฟเวอร์จริง ๆ (ไม่ใช่แค่ที่เลือกไว้) — ใช้ตามเก็บ
    // ล็อกค้างเวลาผู้ใช้เปลี่ยนใจเลือกน้อยลง เพราะผังที่นั่งในมือยังไม่รู้ว่าล็อกไปแล้ว
    lockedSeatIds: [],
    // คันที่ผัง/ล็อกปัจจุบันเป็นของมัน (null = รอบนี้มีรถคันเดียว) — ที่นั่ง A1 ของ
    // รถบัสกับ A1 ของรถตู้เป็นคนละที่ ปลดล็อกผิดคันจะปล่อยของคนอื่นไม่ออก
    vehicleOptionId: null,
    _onExpireCallbacks: [],
  }),

  getters: {
    hasSelectedSeats: (state) => state.selectedSeats.length > 0,
    selectedSeatIds: (state) => state.selectedSeats.map(s => s.id),
    hasActiveBooking: (state) => state.activeBookingInfo !== null && state.countdownSeconds > 0,
    // Total allotted seconds for the window currently being counted down. Prefer the
    // real length handed to us by the server (seat-lock TTL, or the pending-booking TTL
    // on the payment step) and only fall back to the local formula when we have none —
    // the two are NOT the same number, so recomputing it here desynced the progress bar.
    bookingTotalSeconds: (state) => state.windowTotalSeconds || calculateDuration(state.activeBookingInfo?.passengerCount),
    bookingTotalMinutes: (state) => Math.round((state.windowTotalSeconds || calculateDuration(state.activeBookingInfo?.passengerCount)) / 60),
    countdownProgress: (state) => {
      const total = state.windowTotalSeconds || calculateDuration(state.activeBookingInfo?.passengerCount);
      if (!total) return 0;
      return Math.min(1, Math.max(0, state.countdownSeconds / total));
    },
  },

  actions: {
    async fetchSeatMap(scheduleId, vehicleOptionId = null) {
      this.loading = true;
      try {
        const res = await api.get(`/schedules/${scheduleId}/seats`, {
          params: vehicleOptionId ? { vehicle_option_id: vehicleOptionId } : {},
        });
        this.seatMap = res.data.data;
        this.vehicleOptionId = res.data.data?.vehicle_option_id ?? null;
        return this.seatMap;
      } finally {
        this.loading = false;
      }
    },

    async lockSeats(scheduleId, seatIds) {
      try {
        const res = await api.post(`/schedules/${scheduleId}/seats/lock`, {
          seat_ids: seatIds,
          vehicle_option_id: this.vehicleOptionId,
        });
        if (res.data.data?.locked) {
          // เส้นตายจริงคือ TTL ที่ SeatLockService ตั้งไว้ใน Redis — มันรวมโบนัสตามระดับ
          // สมาชิกที่ฝั่งเว็บไม่รู้ด้วย เมื่อก่อนเราตัดให้เหลือตามสูตรของตัวเอง สมาชิกที่
          // ได้เวลาเพิ่มจึงถูกไล่ออกจากฟอร์มก่อนที่ล็อกจะหมดจริง สูตรในไฟล์นี้เหลือไว้เป็น
          // ทางถอยกรณีเซิร์ฟเวอร์ไม่ส่ง expires_at มาเท่านั้น
          const serverExpiry = res.data.data.expires_at ? new Date(res.data.data.expires_at) : null;
          const expiry = serverExpiry && !Number.isNaN(serverExpiry.getTime())
            ? serverExpiry
            : new Date(Date.now() + calculateDuration(seatIds.length) * 1000);
          this.lockExpiry = expiry.toISOString();
          this.windowTotalSeconds = Math.max(1, Math.round((expiry.getTime() - Date.now()) / 1000));
          // Record passenger count so total-duration getters match this lock window
          this.activeBookingInfo = { ...(this.activeBookingInfo || {}), passengerCount: seatIds.length };
          saveSession(this.lockExpiry, this.activeBookingInfo, this.selectedSeats, this.windowTotalSeconds);
          this.startCountdown();
          // ปล่อยที่นั่งที่ตัวเองเคยล็อกไว้แต่ไม่ได้เลือกแล้ว — ไม่งั้นคนที่เปลี่ยนใจ
          // จาก 8 ที่เหลือ 1 ที่ จะแช่อีก 7 ที่ไว้จนหมด TTL (นานถึง 45 นาที)
          await this.releaseStaleOwnLocks(scheduleId, seatIds);
          this.lockedSeatIds = [...seatIds];
        }
        return res.data;
      } catch (err) {
        throw err.response?.data || err;
      }
    },

    /**
     * ปลดล็อกที่นั่งที่ผู้ใช้คนนี้ถืออยู่ในรอบนี้ แต่ไม่ได้อยู่ในรายการที่เลือกไว้แล้ว
     * รวมสองแหล่ง: ธงจากผังที่นั่ง (ล็อกที่ค้างมาจากรอบก่อน/แท็บอื่น) กับล็อกที่หน้านี้
     * เพิ่งขอไปเอง — เงียบเสมอ ล้มเหลวก็ไม่ควรขวางการจอง
     */
    async releaseStaleOwnLocks(scheduleId, keepSeatIds = []) {
      const keep = new Set(keepSeatIds);
      const held = new Set(this.lockedSeatIds);
      (this.seatMap?.seats || [])
        .filter(seat => seat.locked_by_current_user && seat.status === 'locked')
        .forEach(seat => held.add(seat.id));

      const stale = [...held].filter(id => !keep.has(id));
      if (stale.length === 0) return;

      try {
        await api.delete(`/seat-locks/${scheduleId}`, {
          data: { seat_ids: stale, vehicle_option_id: this.vehicleOptionId },
        });
        stale.forEach(id => this.updateSeatStatus(id, 'available'));
      } catch {}
    },

    async unlockSeats(scheduleId) {
      // ปล่อยทั้งที่เลือกไว้และที่ล็อกไว้จริง — ทั้งสองชุดอาจต่างกันถ้าผู้ใช้เปลี่ยนใจ
      const seatIds = [...new Set([...this.selectedSeatIds, ...this.lockedSeatIds])];
      if (seatIds.length === 0) return;
      try {
        await api.delete(`/schedules/${scheduleId}/seats/lock`, {
          data: { seat_ids: seatIds, vehicle_option_id: this.vehicleOptionId },
        });
      } catch {}
      this.clearSelection();
    },

    setActiveBookingInfo(info) {
      this.activeBookingInfo = info;
      saveSession(this.lockExpiry, this.activeBookingInfo, this.selectedSeats, this.windowTotalSeconds);
    },

    /**
     * ตั้งเส้นตายของนาฬิกาให้ตรงกับที่เซิร์ฟเวอร์บอกมาตรง ๆ (เช่น expires_at ของการจอง
     * ที่ยังไม่ชำระ) — ใช้แทนการคำนวณเวลาเองในหน้าที่ไม่ได้เป็นคนล็อกที่นั่ง
     */
    setDeadline(expiry, totalSeconds = null) {
      const at = expiry ? new Date(expiry) : null;
      if (!at || Number.isNaN(at.getTime())) return;

      this.lockExpiry = at.toISOString();
      this.windowTotalSeconds = totalSeconds > 0 ? Math.round(totalSeconds) : null;
      saveSession(this.lockExpiry, this.activeBookingInfo, this.selectedSeats, this.windowTotalSeconds);
    },

    saveStep(step) {
      if (this.activeBookingInfo) {
        this.activeBookingInfo = { ...this.activeBookingInfo, step };
        saveSession(this.lockExpiry, this.activeBookingInfo, this.selectedSeats, this.windowTotalSeconds);
      }
    },

    updateBookingDuration(count) {
      if (!this.activeBookingInfo || !this.lockExpiry) return;

      const startedAt = this.activeBookingInfo.startedAt || (new Date(this.lockExpiry).getTime() - calculateDuration(this.activeBookingInfo.passengerCount || 1) * 1000);
      const duration = calculateDuration(count);
      const newExpiry = new Date(startedAt + duration * 1000);

      if (newExpiry > new Date(this.lockExpiry)) {
        this.lockExpiry = newExpiry.toISOString();
        this.windowTotalSeconds = Math.max(1, Math.round((newExpiry.getTime() - startedAt) / 1000));
        this.activeBookingInfo.passengerCount = count;
        saveSession(this.lockExpiry, this.activeBookingInfo, this.selectedSeats, this.windowTotalSeconds);
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
      this.windowTotalSeconds = null;
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
      saveSession(this.lockExpiry, this.activeBookingInfo, this.selectedSeats, this.windowTotalSeconds);
    },

    updateSeatStatus(seatId, status) {
      if (!this.seatMap?.seats) return;
      const seat = this.seatMap.seats.find(s => s.id === seatId);
      if (!seat) return;
      seat.status = status;
      // ที่นั่งที่กลับมาว่างไม่ใช่ล็อกของใครอีกต่อไป — ไม่งั้นธงเดิมค้างและยังโชว์ว่า "ของคุณ"
      if (status !== 'locked') seat.locked_by_current_user = false;
    },

    clearSelection() {
      this.selectedSeats = [];
      this.lockedSeatIds = [];
      this.lockExpiry = null;
      this.windowTotalSeconds = null;
      this.activeBookingInfo = null;
      // NOTE: do NOT reset _onExpireCallbacks here. Listeners are owned by the
      // components that registered them (via onExpire/offExpire on mount/unmount).
      // Wiping them on every cancel/expiry left later bookings with no expiry
      // handler, so seats never got released client-side the second time around.
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

    startManualCountdown(tripTitle, scheduleId, region = null, passengerCount = 1) {
      this.stopCountdown();
      const duration = calculateDuration(passengerCount);
      this.lockExpiry = new Date(Date.now() + duration * 1000).toISOString();
      this.windowTotalSeconds = duration;
      this.activeBookingInfo = { tripTitle, scheduleId, region, startedAt: Date.now(), passengerCount };
      saveSession(this.lockExpiry, this.activeBookingInfo, this.selectedSeats, this.windowTotalSeconds);
      this.startCountdown();
    },
  },
});
