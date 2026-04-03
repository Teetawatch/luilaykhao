import { defineStore } from 'pinia';
import api from '../lib/axios';

export const useAdminStore = defineStore('admin', {
  state: () => ({
    dashboard: null,
    trips: { data: [], meta: null },
    schedules: { data: [], meta: null },
    bookings: { data: [], meta: null },
    vehicles: { data: [], meta: null },
    users: { data: [], meta: null },
    calendarEvents: [],
    customers: { data: [], meta: null },
    maintenances: { data: [], meta: null },
    loading: false,
    error: null,
  }),

  actions: {
    // ─── Dashboard ──────────────────
    async fetchDashboard() {
      this.loading = true;
      try {
        const res = await api.get('/admin/dashboard');
        this.dashboard = res.data.data;
      } catch (e) {
        this.error = e.response?.data?.message || 'เกิดข้อผิดพลาด';
      } finally {
        this.loading = false;
      }
    },

    // ─── Trips ──────────────────────
    async fetchTrips(params = {}) {
      this.loading = true;
      try {
        const res = await api.get('/admin/trips', { params });
        this.trips = { data: res.data.data, meta: res.data.meta };
      } catch (e) {
        this.error = e.response?.data?.message || 'เกิดข้อผิดพลาด';
      } finally {
        this.loading = false;
      }
    },

    async createTrip(data) {
      const res = await api.post('/admin/trips', data);
      return res.data;
    },

    async updateTrip(id, data) {
      const res = await api.put(`/admin/trips/${id}`, data);
      return res.data;
    },

    async deleteTrip(id) {
      const res = await api.delete(`/admin/trips/${id}`);
      return res.data;
    },

    // ─── Schedules ──────────────────
    async fetchSchedules(params = {}) {
      this.loading = true;
      try {
        const res = await api.get('/admin/schedules', { params });
        this.schedules = { data: res.data.data, meta: res.data.meta };
      } catch (e) {
        this.error = e.response?.data?.message || 'เกิดข้อผิดพลาด';
      } finally {
        this.loading = false;
      }
    },

    async createSchedule(data) {
      const res = await api.post('/admin/schedules', data);
      return res.data;
    },

    async updateSchedule(id, data) {
      const res = await api.put(`/admin/schedules/${id}`, data);
      return res.data;
    },

    async deleteSchedule(id) {
      const res = await api.delete(`/admin/schedules/${id}`);
      return res.data;
    },

    // ─── Bookings ───────────────────
    async fetchBookings(params = {}) {
      this.loading = true;
      try {
        const res = await api.get('/admin/bookings', { params });
        this.bookings = { data: res.data.data, meta: res.data.meta };
      } catch (e) {
        this.error = e.response?.data?.message || 'เกิดข้อผิดพลาด';
      } finally {
        this.loading = false;
      }
    },

    async updateBookingStatus(ref, status, reason = null) {
      const res = await api.put(`/admin/bookings/${ref}/status`, {
        status,
        cancellation_reason: reason,
      });
      return res.data;
    },

    async fetchManifest(scheduleId) {
      const res = await api.get(`/admin/schedules/${scheduleId}/manifest`);
      return res.data;
    },

    // ─── Vehicles ───────────────────
    async fetchVehicles(params = {}) {
      this.loading = true;
      try {
        const res = await api.get('/admin/vehicles', { params });
        this.vehicles = { data: res.data.data, meta: res.data.meta };
      } catch (e) {
        this.error = e.response?.data?.message || 'เกิดข้อผิดพลาด';
      } finally {
        this.loading = false;
      }
    },

    async createVehicle(data) {
      const res = await api.post('/admin/vehicles', data);
      return res.data;
    },

    async updateVehicle(id, data) {
      const res = await api.put(`/admin/vehicles/${id}`, data);
      return res.data;
    },

    async deleteVehicle(id) {
      const res = await api.delete(`/admin/vehicles/${id}`);
      return res.data;
    },

    async fetchVehiclePickupPoints(vehicleId) {
      const res = await api.get(`/admin/vehicles/${vehicleId}/pickup-points`);
      return res.data;
    },

    async createVehiclePickupPoint(vehicleId, data) {
      const res = await api.post(`/admin/vehicles/${vehicleId}/pickup-points`, data);
      return res.data;
    },

    async updateVehiclePickupPoint(vehicleId, pointId, data) {
      const res = await api.put(`/admin/vehicles/${vehicleId}/pickup-points/${pointId}`, data);
      return res.data;
    },

    async deleteVehiclePickupPoint(vehicleId, pointId) {
      const res = await api.delete(`/admin/vehicles/${vehicleId}/pickup-points/${pointId}`);
      return res.data;
    },

    // ─── Users ──────────────────────
    async fetchUsers(params = {}) {
      this.loading = true;
      try {
        const res = await api.get('/admin/users', { params });
        this.users = { data: res.data.data, meta: res.data.meta };
      } catch (e) {
        this.error = e.response?.data?.message || 'เกิดข้อผิดพลาด';
      } finally {
        this.loading = false;
      }
    },

    async createUser(data) {
      const res = await api.post('/admin/users', data);
      return res.data;
    },

    async updateUser(id, data) {
      const res = await api.put(`/admin/users/${id}`, data);
      return res.data;
    },

    async deleteUser(id) {
      const res = await api.delete(`/admin/users/${id}`);
      return res.data;
    },

    // ─── Calendar ────────────────────
    async fetchCalendarSchedules(params = {}) {
      this.loading = true;
      try {
        const res = await api.get('/admin/calendar/schedules', { params });
        this.calendarEvents = res.data.data;
      } catch (e) {
        this.error = e.response?.data?.message || 'เกิดข้อผิดพลาด';
      } finally {
        this.loading = false;
      }
    },

    // ─── Customers ───────────────────
    async fetchCustomers(params = {}) {
      this.loading = true;
      try {
        const res = await api.get('/admin/customers', { params });
        this.customers = { data: res.data.data, meta: res.data.meta };
      } catch (e) {
        this.error = e.response?.data?.message || 'เกิดข้อผิดพลาด';
      } finally {
        this.loading = false;
      }
    },

    async fetchCustomerDetail(id) {
      const res = await api.get(`/admin/customers/${id}`);
      return res.data;
    },

    // ─── Maintenance ─────────────────
    async fetchMaintenances(params = {}) {
      this.loading = true;
      try {
        const res = await api.get('/admin/maintenances', { params });
        this.maintenances = { data: res.data.data, meta: res.data.meta };
      } catch (e) {
        this.error = e.response?.data?.message || 'เกิดข้อผิดพลาด';
      } finally {
        this.loading = false;
      }
    },

    async createMaintenance(data) {
      const res = await api.post('/admin/maintenances', data);
      return res.data;
    },

    async updateMaintenance(id, data) {
      const res = await api.put(`/admin/maintenances/${id}`, data);
      return res.data;
    },

    async deleteMaintenance(id) {
      const res = await api.delete(`/admin/maintenances/${id}`);
      return res.data;
    },

    // ─── Reports ─────────────────────
    async fetchReportBookings(params = {}) {
      const res = await api.get('/admin/reports/bookings', { params });
      return res.data;
    },

    async fetchReportRevenue(params = {}) {
      const res = await api.get('/admin/reports/revenue', { params });
      return res.data;
    },

    async fetchReportVehicles() {
      const res = await api.get('/admin/reports/vehicles');
      return res.data;
    },

    // ─── QR Check-in ─────────────────
    async checkInByQr(qrCode) {
      const res = await api.post('/admin/check-in', { qr_code: qrCode });
      return res.data;
    },

    async checkInByRef(ref) {
      const res = await api.post(`/admin/check-in/${ref}`);
      return res.data;
    },
  },
});
