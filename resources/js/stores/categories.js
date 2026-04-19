import { defineStore } from 'pinia';
import api from '../lib/axios';

export const useCategoriesStore = defineStore('categories', {
  state: () => ({
    categories: [],
    loading: false,
  }),

  actions: {
    async fetchCategories() {
      if (this.categories.length > 0) return this.categories;
      this.loading = true;
      try {
        const res = await api.get('/categories');
        this.categories = res.data.data;
        return this.categories;
      } finally {
        this.loading = false;
      }
    },

    async fetchAdminCategories() {
      this.loading = true;
      try {
        const res = await api.get('/admin/categories');
        this.categories = res.data.data;
        return this.categories;
      } finally {
        this.loading = false;
      }
    },

    async createCategory(data) {
      const res = await api.post('/admin/categories', data);
      await this.fetchAdminCategories();
      return res.data.data;
    },

    async updateCategory(id, data) {
      const res = await api.put(`/admin/categories/${id}`, data);
      await this.fetchAdminCategories();
      return res.data.data;
    },

    async deleteCategory(id) {
      const res = await api.delete(`/admin/categories/${id}`);
      await this.fetchAdminCategories();
      return res.data.data;
    },
  },
});
