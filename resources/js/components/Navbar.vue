<template>
  <nav class="navbar-root sticky top-0 z-50 bg-white/90 backdrop-blur-md border-b border-sand-dark/50 shadow-sm transition-all duration-300">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-20">

        <!-- Logo -->
        <router-link to="/" class="flex items-center gap-3 shrink-0 group">
          <div class="relative flex items-center justify-center w-11 h-11 bg-sand/80 rounded-xl group-hover:bg-white group-hover:shadow-sm transition-all duration-300">
            <img src="/images/logo.png" alt="ลุยเลเขา Logo" class="w-8 h-8 object-contain transition-transform duration-300 group-hover:scale-110" />
          </div>
          <span class="font-anuphan text-xl font-bold tracking-tight text-primary">ลุยเลเขา</span>
        </router-link>

        <!-- Desktop Menu -->
        <div class="hidden md:flex items-center gap-2">
          <div class="flex items-center gap-1 mr-4">
            <router-link
              v-for="link in navLinks"
              :key="link.to"
              :to="link.to"
              class="nav-link flex items-center gap-2 px-4 py-2.5 rounded-full text-sm font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200"
            >
              <span class="material-symbols-rounded text-[18px]">{{ link.icon }}</span>
              {{ link.label }}
            </router-link>
          </div>

          <template v-if="auth.isLoggedIn">
            <router-link
              to="/my-bookings"
              class="nav-link flex items-center gap-2 px-4 py-2.5 rounded-full text-sm font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200"
            >
              <span class="material-symbols-rounded text-[18px]">confirmation_number</span>
              การจองของฉัน
            </router-link>
            <router-link
              to="/loyalty"
              class="nav-link flex items-center gap-2 px-4 py-2.5 rounded-full text-sm font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200"
            >
              <span class="material-symbols-rounded text-[18px]">stars</span>
              แต้มสะสม
            </router-link>
            <router-link
              v-if="isAdmin"
              to="/admin"
              class="nav-link flex items-center gap-2 px-4 py-2.5 rounded-full text-sm font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200"
            >
              <span class="material-symbols-rounded text-[18px]">admin_panel_settings</span>
              Admin
            </router-link>

            <!-- Divider -->
            <div class="w-px h-6 bg-sand-dark mx-2"></div>

            <!-- Notification Bell -->
            <router-link
              to="/notifications"
              class="relative flex items-center justify-center w-10 h-10 rounded-full hover:bg-sand transition-colors"
              title="การแจ้งเตือน"
            >
              <span class="material-symbols-rounded text-[22px] text-text-mid">notifications</span>
              <span
                v-if="unreadNotifications > 0"
                class="absolute top-1.5 right-1.5 w-4 h-4 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center leading-none border-2 border-white box-content">
                {{ unreadNotifications > 9 ? '9+' : unreadNotifications }}
              </span>
            </router-link>

            <!-- User Menu -->
            <div class="flex items-center gap-3 ml-2">
              <div class="flex items-center gap-2.5 pl-1.5 pr-4 py-1.5 rounded-full bg-sand/60 border border-sand-dark/60 hover:bg-sand transition-colors">
                <div class="w-8 h-8 rounded-full bg-primary flex items-center justify-center shadow-sm">
                  <span class="text-white text-xs font-bold">{{ auth.userName?.charAt(0)?.toUpperCase() }}</span>
                </div>
                <span class="text-sm font-bold text-text-dark max-w-[120px] truncate">{{ auth.userName }}</span>
              </div>
              <button
                @click="handleLogout"
                class="flex items-center justify-center w-10 h-10 rounded-full text-text-muted hover:text-red-600 hover:bg-red-50 transition-all duration-200 cursor-pointer"
                title="ออกจากระบบ"
              >
                <span class="material-symbols-rounded text-[20px]">logout</span>
              </button>
            </div>
          </template>

          <template v-else>
            <div class="flex items-center gap-3 ml-2">
              <router-link
                to="/login"
                class="flex items-center gap-2 bg-primary text-white px-6 py-2.5 rounded-full font-semibold text-sm hover:bg-primary-mid transition-all duration-300 shadow-md shadow-primary/20 hover:shadow-lg hover:-translate-y-0.5"
              >
                <span class="material-symbols-rounded text-[18px]">login</span>
                เข้าสู่ระบบ
              </router-link>
            </div>
          </template>
        </div>

        <!-- Mobile menu button -->
        <button
          @click="mobileOpen = !mobileOpen"
          class="md:hidden flex items-center justify-center w-11 h-11 rounded-full hover:bg-sand transition-colors duration-200 cursor-pointer focus:outline-none"
          aria-label="Toggle menu"
        >
          <span class="material-symbols-rounded text-[24px] text-text-dark transition-transform duration-300" :class="{ 'rotate-180 scale-90': mobileOpen }">
            {{ mobileOpen ? 'close' : 'menu' }}
          </span>
        </button>
      </div>
    </div>

    <!-- Mobile Menu -->
    <Transition name="mobile-menu">
      <div v-if="mobileOpen" class="md:hidden bg-white/95 backdrop-blur-xl border-t border-sand-dark/40 absolute w-full shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-6 space-y-2 max-h-[calc(100vh-5rem)] overflow-y-auto">

          <router-link
            v-for="link in navLinks"
            :key="link.to"
            :to="link.to"
            @click="mobileOpen = false"
            class="flex items-center gap-3.5 px-5 py-3.5 rounded-2xl text-base font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200 active:scale-[0.98]"
          >
            <span class="material-symbols-rounded text-[22px]">{{ link.icon }}</span>
            {{ link.label }}
          </router-link>

          <template v-if="auth.isLoggedIn">
            <div class="w-full h-px bg-sand-dark/60 my-3"></div>
            
            <router-link
              to="/my-bookings"
              @click="mobileOpen = false"
              class="flex items-center gap-3.5 px-5 py-3.5 rounded-2xl text-base font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200 active:scale-[0.98]"
            >
              <span class="material-symbols-rounded text-[22px]">confirmation_number</span>
              การจองของฉัน
            </router-link>
            <router-link
              to="/my-reviews"
              @click="mobileOpen = false"
              class="flex items-center gap-3.5 px-5 py-3.5 rounded-2xl text-base font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200 active:scale-[0.98]"
            >
              <span class="material-symbols-rounded text-[22px]">reviews</span>
              รีวิวของฉัน
            </router-link>
            <router-link
              to="/loyalty"
              @click="mobileOpen = false"
              class="flex items-center gap-3.5 px-5 py-3.5 rounded-2xl text-base font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200 active:scale-[0.98]"
            >
              <span class="material-symbols-rounded text-[22px]">stars</span>
              แต้มสะสม
            </router-link>
            <router-link
              to="/notifications"
              @click="mobileOpen = false"
              class="flex items-center justify-between px-5 py-3.5 rounded-2xl text-base font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200 active:scale-[0.98]"
            >
              <div class="flex items-center gap-3.5">
                <span class="material-symbols-rounded text-[22px]">notifications</span>
                การแจ้งเตือน
              </div>
              <span
                v-if="unreadNotifications > 0"
                class="bg-red-500 text-white text-xs font-bold rounded-full px-2.5 py-1">
                {{ unreadNotifications }}
              </span>
            </router-link>
            
            <router-link
              v-if="isAdmin"
              to="/admin"
              @click="mobileOpen = false"
              class="flex items-center gap-3.5 px-5 py-3.5 rounded-2xl text-base font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200 active:scale-[0.98]"
            >
              <span class="material-symbols-rounded text-[22px]">admin_panel_settings</span>
              Admin Panel
            </router-link>

            <!-- User info + Logout -->
            <div class="mt-4 p-4 bg-sand/50 rounded-3xl border border-sand-dark/40">
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 rounded-full bg-primary flex items-center justify-center shadow-md">
                    <span class="text-white text-sm font-bold">{{ auth.userName?.charAt(0)?.toUpperCase() }}</span>
                  </div>
                  <div>
                    <div class="text-sm font-bold text-text-dark">{{ auth.userName }}</div>
                    <div class="text-xs font-medium text-text-muted">จัดการบัญชี</div>
                  </div>
                </div>
                <button
                  @click="handleLogout"
                  class="flex items-center justify-center w-10 h-10 rounded-full text-red-500 hover:bg-red-50 transition-all duration-200 active:scale-[0.95]"
                >
                  <span class="material-symbols-rounded text-[22px]">logout</span>
                </button>
              </div>
            </div>
          </template>

          <template v-else>
            <div class="flex flex-col gap-3 mt-4">
              <router-link
                to="/login"
                @click="mobileOpen = false"
                class="flex items-center justify-center gap-2 py-3.5 rounded-xl text-base font-bold text-primary bg-sand border border-sand-dark/50 hover:bg-sand-dark/50 transition-all duration-200 active:scale-[0.98]"
              >
                <span class="material-symbols-rounded text-[20px]">login</span>
                เข้าสู่ระบบ
              </router-link>
              <router-link
                to="/register"
                @click="mobileOpen = false"
                class="flex items-center justify-center gap-2 py-3.5 rounded-xl text-base font-bold text-white bg-primary hover:bg-primary-mid transition-all duration-200 active:scale-[0.98] shadow-md shadow-primary/20"
              >
                <span class="material-symbols-rounded text-[20px]">person_add</span>
                สมัครสมาชิก
              </router-link>
            </div>
          </template>
        </div>
      </div>
    </Transition>
  </nav>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import api from '../lib/axios';

const auth = useAuthStore();
const router = useRouter();
const mobileOpen = ref(false);
const unreadNotifications = ref(0);

async function fetchUnreadCount() {
  if (!auth.isLoggedIn) return;
  try {
    const res = await api.get('/notifications/unread-count');
    unreadNotifications.value = res.data.data.count;
  } catch {}
}

let pollInterval = null;
onMounted(() => {
  fetchUnreadCount();
  pollInterval = setInterval(fetchUnreadCount, 60000);
});
onUnmounted(() => clearInterval(pollInterval));

const navLinks = [
  { to: '/trips', icon: 'explore', label: 'กิจกรรมทั้งหมด' },
  { to: '/about', icon: 'info', label: 'เกี่ยวกับเรา' },
  { to: '/goal', icon: 'flag', label: 'จุดมุ่งหมาย' },
];

const isAdmin = computed(() => {
  const roles = auth.user?.roles?.map(r => typeof r === 'string' ? r : r.name) || [];
  return roles.includes('admin') || roles.includes('operator');
});

async function handleLogout() {
  await auth.logout();
  mobileOpen.value = false;
  router.push('/');
}
</script>

<style scoped>
/* Mobile menu transition */
.mobile-menu-enter-active,
.mobile-menu-leave-active {
  transition: all 0.25s ease;
  overflow: hidden;
}
.mobile-menu-enter-from,
.mobile-menu-leave-to {
  opacity: 0;
  max-height: 0;
  transform: translateY(-8px);
}
.mobile-menu-enter-to,
.mobile-menu-leave-from {
  opacity: 1;
  max-height: 500px;
  transform: translateY(0);
}

/* Active route highlight */
.nav-link.router-link-active {
  color: var(--color-primary);
  background-color: var(--color-sand);
}

/* Respect reduced motion */
@media (prefers-reduced-motion: reduce) {
  .mobile-menu-enter-active,
  .mobile-menu-leave-active {
    transition: none;
  }
}
</style>
