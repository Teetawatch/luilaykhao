<template>
  <div class="min-h-screen flex flex-col" v-if="!isAdminRoute">
    <Navbar />
    <main class="flex-1">
      <router-view />
    </main>
    <Footer />
  </div>
  <router-view v-else />
  <ToastNotification />
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import Navbar from './components/Navbar.vue';
import Footer from './components/Footer.vue';
import ToastNotification from './components/ToastNotification.vue';

const route = useRoute();
const isAdminRoute = computed(() => route.path.startsWith('/admin'));
</script>
