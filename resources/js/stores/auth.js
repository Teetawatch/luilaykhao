import { defineStore } from 'pinia';
import api from '../lib/axios';
import { useSeatsStore } from './seats';

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: JSON.parse(localStorage.getItem('auth_user') || 'null'),
    token: localStorage.getItem('auth_token') || null,
    loading: false,
  }),

  getters: {
    isLoggedIn: (state) => !!state.token,
    userName: (state) => state.user?.name || '',
    // Compact name for tight spots (e.g. navbar): keep the first word in full and
    // abbreviate every following word to its initial — "สมชาย วงศ์สุวรรณ" → "สมชาย ว."
    shortName: (state) => {
      const full = (state.user?.name || '').trim();
      if (!full) return '';
      const [first, ...rest] = full.split(/\s+/);
      if (rest.length === 0) return first;
      const initials = rest
        .map((word) => Array.from(word)[0])
        .filter(Boolean)
        .map((char) => `${char}.`)
        .join(' ');
      return initials ? `${first} ${initials}` : first;
    },
  },

  actions: {
    async register(data) {
      this.loading = true;
      try {
        const res = await api.post('/auth/register', data);
        this.setAuth(res.data.data);
        return res.data;
      } finally {
        this.loading = false;
      }
    },

    async login(data) {
      this.loading = true;
      try {
        const res = await api.post('/auth/login', data);
        this.setAuth(res.data.data);
        useSeatsStore().restoreCountdown();
        return res.data;
      } finally {
        this.loading = false;
      }
    },

    async logout() {
      try {
        await api.post('/auth/logout');
      } catch {}
      this.clearAuth();
    },

    async fetchUser() {
      try {
        const res = await api.get('/auth/me');
        this.user = res.data.data;
        localStorage.setItem('auth_user', JSON.stringify(this.user));
      } catch {
        this.clearAuth();
      }
    },

    setAuth({ user, token }) {
      this.user = user;
      this.token = token;
      localStorage.setItem('auth_token', token);
      localStorage.setItem('auth_user', JSON.stringify(user));
    },

    clearAuth() {
      this.user = null;
      this.token = null;
      localStorage.removeItem('auth_token');
      localStorage.removeItem('auth_user');
      useSeatsStore().pauseSession();
    },
  },
});
