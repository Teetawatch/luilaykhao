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
    contacts: { data: [], meta: null },
    articles: { data: [], meta: null },
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

    async bulkUpdateSchedules(ids, data) {
      const res = await api.patch('/admin/schedules/bulk-update', { ids, data });
      return res.data;
    },

    async deleteSchedule(id) {
      const res = await api.delete(`/admin/schedules/${id}`);
      return res.data;
    },

    async fetchScheduleStaff(scheduleId) {
      const res = await api.get(`/admin/schedules/${scheduleId}/staff`);
      return res.data;
    },

    async syncScheduleStaff(scheduleId, staffIds = []) {
      const res = await api.put(`/admin/schedules/${scheduleId}/staff`, { staff_ids: staffIds });
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

    async updateBooking(ref, data) {
      const res = await api.post(`/admin/bookings/${ref}`, data);
      return res.data;
    },

    async refundPreview(ref) {
      const res = await api.get(`/admin/bookings/${ref}/refund-preview`);
      return res.data?.data ?? res.data;
    },

    // Records a refund via the dedicated endpoint (sets refund fields, frees
    // seats, notifies the customer) and optionally attaches a transfer slip.
    async processRefund(ref, { amount, note = null, slip = null }) {
      const form = new FormData();
      form.append('refund_amount', amount);
      if (note) form.append('note', note);
      if (slip) form.append('refund_slip', slip);
      const res = await api.post(`/admin/bookings/${ref}/refund`, form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      return res.data;
    },

    async deleteBooking(ref) {
      const res = await api.delete(`/admin/bookings/${ref}`);
      return res.data;
    },

    async fetchManifest(scheduleId) {
      const res = await api.get(`/admin/schedules/${scheduleId}/manifest`);
      return res.data;
    },

    async createManualBooking(data) {
      const res = await api.post('/admin/bookings/manual', data);
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

    async fetchStaffUsers(params = {}) {
      this.loading = true;
      try {
        const res = await api.get('/admin/staff/users', { params });
        return { data: res.data.data, meta: res.data.meta };
      } finally {
        this.loading = false;
      }
    },

    async fetchStaffRoster(params = {}) {
      const res = await api.get('/admin/staff/roster', { params });
      return res.data.data;
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

    // ─── Contacts ────────────────────
    async fetchContacts(params = {}) {
      this.loading = true;
      try {
        const res = await api.get('/admin/contacts', { params });
        this.contacts = { data: res.data };
      } catch (e) {
        this.error = e.response?.data?.message || 'เกิดข้อผิดพลาด';
      } finally {
        this.loading = false;
      }
    },

    async markContactAsRead(id) {
      const res = await api.put(`/admin/contacts/${id}/read`);
      return res.data;
    },

    async deleteContact(id) {
      const res = await api.delete(`/admin/contacts/${id}`);
      return res.data;
    },

    // ─── Finance: สรุปกำไร/ค่าใช้จ่าย ──────────────
    async fetchFinanceTrips(params = {}) {
      const res = await api.get('/admin/finance/trips', { params });
      return res.data.data;
    },

    async fetchTripScheduleProfit(tripId) {
      const res = await api.get(`/admin/finance/trips/${tripId}/schedules`);
      return res.data.data;
    },

    async fetchExpenseTemplates(tripId) {
      const res = await api.get(`/admin/finance/trips/${tripId}/templates`);
      return res.data.data;
    },

    async createExpenseTemplate(tripId, data) {
      const res = await api.post(`/admin/finance/trips/${tripId}/templates`, data);
      return res.data.data;
    },

    async updateExpenseTemplate(tripId, id, data) {
      const res = await api.put(`/admin/finance/trips/${tripId}/templates/${id}`, data);
      return res.data.data;
    },

    async deleteExpenseTemplate(tripId, id) {
      const res = await api.delete(`/admin/finance/trips/${tripId}/templates/${id}`);
      return res.data;
    },

    async fetchScheduleExpenses(scheduleId) {
      const res = await api.get(`/admin/finance/schedules/${scheduleId}/expenses`);
      return res.data.data;
    },

    async createScheduleExpense(scheduleId, data) {
      const res = await api.post(`/admin/finance/schedules/${scheduleId}/expenses`, data);
      return res.data.data;
    },

    async applyExpenseTemplates(scheduleId) {
      const res = await api.post(`/admin/finance/schedules/${scheduleId}/expenses/apply-templates`);
      return res.data;
    },

    async copyExpensesTo(scheduleId, payload) {
      const res = await api.post(`/admin/finance/schedules/${scheduleId}/expenses/copy-to`, payload);
      return res.data;
    },

    async updateScheduleExpense(scheduleId, id, data) {
      const res = await api.put(`/admin/finance/schedules/${scheduleId}/expenses/${id}`, data);
      return res.data.data;
    },

    async deleteScheduleExpense(scheduleId, id) {
      const res = await api.delete(`/admin/finance/schedules/${scheduleId}/expenses/${id}`);
      return res.data.data;
    },

    // ─── Blog Articles ──────────────
    async fetchArticles(params = {}) {
      this.loading = true;
      try {
        const res = await api.get('/admin/articles', { params });
        this.articles = { data: res.data.data, meta: res.data.meta };
      } catch (e) {
        this.error = e.response?.data?.message || 'เกิดข้อผิดพลาด';
      } finally {
        this.loading = false;
      }
    },

    async fetchArticle(id) {
      const res = await api.get(`/admin/articles/${id}`);
      return res.data.data;
    },

    async createArticle(data) {
      const res = await api.post('/admin/articles', data);
      return res.data.data;
    },

    async updateArticle(id, data) {
      const res = await api.put(`/admin/articles/${id}`, data);
      return res.data.data;
    },

    async publishArticle(id, published) {
      const res = await api.patch(`/admin/articles/${id}/publish`, { published });
      return res.data.data;
    },

    async deleteArticle(id) {
      const res = await api.delete(`/admin/articles/${id}`);
      return res.data;
    },

    async fetchArticleCategories() {
      const res = await api.get('/admin/article-categories');
      return res.data.data;
    },

    async createArticleCategory(data) {
      const res = await api.post('/admin/article-categories', data);
      return res.data.data;
    },

    async fetchArticleTags() {
      const res = await api.get('/admin/article-tags');
      return res.data.data;
    },

    // Reuse the shared media upload endpoint (stores to R2) for cover + inline images.
    async uploadArticleImage(file) {
      const fd = new FormData();
      fd.append('file', file);
      const res = await api.post('/admin/upload-image', fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      return res.data.data?.url ?? res.data.url;
    },

  },
});
