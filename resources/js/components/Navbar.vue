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
          <div class="flex items-center gap-1 mr-4 h-full">
            <template v-for="link in navLinks" :key="link.label">
              <!-- Dropdown Menu -->
              <div v-if="link.children" ref="navDropdownRef" class="relative h-full flex items-center">
                <div 
                  class="nav-link flex items-center gap-2 px-4 py-2.5 rounded-full text-sm font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200 cursor-pointer"
                  :class="{ 'text-primary bg-sand': isAboutActive }"
                  @click.stop="navDropdownOpen = !navDropdownOpen"
                >
                  <span class="material-symbols-rounded text-[18px] font-variation-settings-'FILL'-0">{{ link.icon }}</span>
                  {{ link.label }}
                  <span class="material-symbols-rounded text-[16px] transition-transform duration-300" :class="{ 'rotate-180': navDropdownOpen }">expand_more</span>
                </div>

                <!-- Dropdown List -->
                <div v-if="navDropdownOpen" class="absolute top-full left-0 w-64 pt-2 transition-all duration-300 transform origin-top-left z-[60] animation-scale-in">
                  <div class="bg-white/95 backdrop-blur-xl rounded-2xl shadow-xl border border-sand-dark/50 overflow-hidden py-2.5">
                    <router-link 
                      v-for="child in link.children" 
                      :key="child.to" 
                      :to="child.to"
                      @click="navDropdownOpen = false"
                      class="flex items-center gap-3.5 px-5 py-3 text-[13px] font-bold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200 border-l-4 border-transparent hover:border-primary"
                      :class="{ 'text-primary bg-sand border-primary': router.currentRoute.value.path === child.to }"
                    >
                      <span class="material-symbols-rounded text-[18px]">{{ child.icon }}</span>
                      {{ child.label }}
                    </router-link>
                  </div>
                </div>
              </div>

              <!-- Simple Link -->
              <router-link
                v-else
                :to="link.to"
                class="nav-link flex items-center gap-2 px-4 py-2.5 rounded-full text-sm font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200"
              >
                <span class="material-symbols-rounded text-[18px]">{{ link.icon }}</span>
                {{ link.label }}
              </router-link>
            </template>
          </div>

          <template v-if="auth.isLoggedIn">
            <!-- User Menu Dropdown -->
            <div ref="userDropdownRef" class="relative h-full flex items-center ml-2">
              <div class="flex items-center gap-2.5 pl-1.5 pr-4 py-1.5 rounded-full bg-sand/60 border border-sand-dark/60 hover:bg-sand transition-colors cursor-pointer" @click.stop="userDropdownOpen = !userDropdownOpen">
                <div class="relative">
                  <div class="w-8 h-8 rounded-full bg-primary flex items-center justify-center shadow-sm overflow-hidden border border-white/50">
                    <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" class="w-full h-full object-cover" />
                    <span v-else class="text-white text-xs font-bold">{{ auth.userName?.charAt(0)?.toUpperCase() }}</span>
                  </div>
                  <span v-if="unreadNotifications > 0" class="absolute -top-1 -right-1 w-3.5 h-3.5 bg-red-500 border-2 border-white rounded-full"></span>
                </div>
                <div class="flex flex-col">
                  <span class="text-[13px] font-bold text-text-dark leading-tight">{{ auth.userName }}</span>
                  <span class="text-[10px] font-bold text-primary uppercase tracking-tighter">{{ isAdmin ? 'ทีมงาน / แอดมิน' : 'สมาชิกลุยเลเขา' }}</span>
                </div>
                <span class="material-symbols-rounded text-[18px] text-text-muted transition-transform duration-300" :class="{ 'rotate-180': userDropdownOpen }">expand_more</span>
              </div>

              <!-- Dropdown List -->
              <div v-if="userDropdownOpen" class="absolute top-full right-0 w-72 pt-2 transition-all duration-300 transform origin-top-right z-[60] animation-scale-in">
                <div class="bg-white/95 backdrop-blur-xl rounded-2xl shadow-xl border border-sand-dark/50 overflow-hidden">
                  
                  <!-- Profile Header (Mobile style but subtle for desktop) -->
                  <div class="px-5 py-4 bg-sand/30 border-b border-sand-dark/40 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-primary flex items-center justify-center shadow-lg shadow-primary/20 overflow-hidden border-2 border-white">
                      <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" class="w-full h-full object-cover" />
                      <span v-else class="text-white text-lg font-bold">{{ auth.userName?.charAt(0)?.toUpperCase() }}</span>
                    </div>
                    <div>
                      <div class="text-[15px] font-bold text-text-dark">{{ auth.userName }}</div>
                  </div>
                </div>

                <!-- Profile Link -->
                <router-link to="/profile" @click="userDropdownOpen = false" class="flex items-center gap-3.5 px-5 py-3.5 bg-sand/10 hover:bg-sand border-b border-sand-dark/40 text-[13px] font-bold text-primary transition-all">
                  <span class="material-symbols-rounded text-[20px]">account_circle</span>
                  จัดการโปรไฟล์ / ข้อมูลส่วนตัว
                </router-link>


                  <!-- Menu Items -->
                  <div class="py-2">
                    <router-link v-if="isAdmin" to="/admin" @click="userDropdownOpen = false" class="flex items-center gap-3.5 px-5 py-3 text-[13px] font-bold text-text-mid hover:text-primary hover:bg-sand transition-all border-l-4 border-transparent hover:border-primary">
                      <span class="material-symbols-rounded text-[20px] text-amber-600">admin_panel_settings</span>
                      Admin Dashboard
                    </router-link>

                    <router-link to="/notifications" @click="userDropdownOpen = false" class="flex items-center justify-between px-5 py-3 text-[13px] font-bold text-text-mid hover:text-primary hover:bg-sand transition-all border-l-4 border-transparent hover:border-primary">
                      <div class="flex items-center gap-3.5">
                        <span class="material-symbols-rounded text-[20px] text-teal-600">notifications</span>
                        การแจ้งเตือน
                      </div>
                      <span v-if="unreadNotifications > 0" class="bg-red-500 text-white text-[10px] font-bold rounded-full px-2 py-0.5 min-w-[20px] text-center">
                        {{ unreadNotifications > 9 ? '9+' : unreadNotifications }}
                      </span>
                    </router-link>

                    <div class="h-px bg-sand-dark/40 mx-4 my-1"></div>

                    <router-link to="/my-bookings" @click="userDropdownOpen = false" class="flex items-center gap-3.5 px-5 py-3 text-[13px] font-bold text-text-mid hover:text-primary hover:bg-sand transition-all border-l-4 border-transparent hover:border-primary">
                      <span class="material-symbols-rounded text-[20px] text-blue-600">confirmation_number</span>
                      การจองของฉัน
                    </router-link>

                    <router-link to="/my-reviews" @click="userDropdownOpen = false" class="flex items-center gap-3.5 px-5 py-3 text-[13px] font-bold text-text-mid hover:text-primary hover:bg-sand transition-all border-l-4 border-transparent hover:border-primary">
                      <span class="material-symbols-rounded text-[20px] text-purple-600">reviews</span>
                      รีวิวของฉัน
                    </router-link>

                    <router-link to="/loyalty" @click="userDropdownOpen = false" class="flex items-center gap-3.5 px-5 py-3 text-[13px] font-bold text-text-mid hover:text-primary hover:bg-sand transition-all border-l-4 border-transparent hover:border-primary">
                      <span class="material-symbols-rounded text-[20px] text-amber-500">stars</span>
                      แต้มสะสมลุยเลเขา
                    </router-link>

                    <div class="h-px bg-sand-dark/40 mx-4 my-1"></div>

                    <button @click="handleLogout" class="w-full flex items-center gap-3.5 px-5 py-3 text-[13px] font-bold text-red-600 hover:bg-red-50 transition-all border-l-4 border-transparent hover:border-red-600">
                      <span class="material-symbols-rounded text-[20px]">logout</span>
                      ออกจากระบบ
                    </button>
                  </div>
                </div>
              </div>
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

          <template v-for="link in navLinks" :key="link.label">
            <div v-if="link.children" class="flex flex-col gap-1">
              <div class="px-5 py-2 text-[11px] font-bold text-text-muted uppercase tracking-wider flex items-center gap-2">
                <span class="material-symbols-rounded text-[16px]">{{ link.icon }}</span>
                {{ link.label }}
              </div>
              <router-link
                v-for="child in link.children"
                :key="child.to"
                :to="child.to"
                @click="mobileOpen = false"
                class="flex items-center gap-3.5 px-8 py-3 rounded-2xl text-base font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200 active:scale-[0.98]"
              >
                <span class="material-symbols-rounded text-[20px]">{{ child.icon }}</span>
                {{ child.label }}
              </router-link>
            </div>
            
            <router-link
              v-else
              :to="link.to"
              @click="mobileOpen = false"
              class="flex items-center gap-3.5 px-5 py-3.5 rounded-2xl text-base font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200 active:scale-[0.98]"
            >
              <span class="material-symbols-rounded text-[22px]">{{ link.icon }}</span>
              {{ link.label }}
            </router-link>
          </template>

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
              to="/profile"
              @click="mobileOpen = false"
              class="flex items-center gap-3.5 px-5 py-3.5 rounded-2xl text-base font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200 active:scale-[0.98]"
            >
              <span class="material-symbols-rounded text-[22px]">account_circle</span>
              จัดการโปรไฟล์
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
                  <div class="w-10 h-10 rounded-full bg-primary flex items-center justify-center shadow-md overflow-hidden border-2 border-white">
                    <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" class="w-full h-full object-cover" />
                    <span v-else class="text-white text-sm font-bold">{{ auth.userName?.charAt(0)?.toUpperCase() }}</span>
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
const navDropdownOpen = ref(false);
const userDropdownOpen = ref(false);
const navDropdownRef = ref(null);
const userDropdownRef = ref(null);

function handleClickOutside(e) {
  const navEl = navDropdownRef.value?.$el ?? navDropdownRef.value;
  const userEl = userDropdownRef.value?.$el ?? userDropdownRef.value;
  if (navEl && !navEl.contains(e.target)) {
    navDropdownOpen.value = false;
  }
  if (userEl && !userEl.contains(e.target)) {
    userDropdownOpen.value = false;
  }
}

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
  document.addEventListener('click', handleClickOutside);
  document.addEventListener('touchstart', handleClickOutside);
});
onUnmounted(() => {
  clearInterval(pollInterval);
  document.removeEventListener('click', handleClickOutside);
  document.removeEventListener('touchstart', handleClickOutside);
});

const navLinks = [
  { to: '/', icon: 'home', label: 'หน้าแรก' },
  { 
    label: 'เกี่ยวกับเรา', 
    icon: 'info',
    to: '/about',
    children: [
      { to: '/about', icon: 'info', label: 'เกี่ยวกับเรา' },
      { to: '/goal', icon: 'flag', label: 'จุดมุ่งหมาย' },
      { to: '/terms', icon: 'gavel', label: 'เงื่อนไขการให้บริการ' },
      { to: '/privacy', icon: 'policy', label: 'นโยบายความเป็นส่วนตัว' },
    ]
  },
    { to: '/trips', icon: 'explore', label: 'กิจกรรมทั้งหมด' },
    { to: '/contact', icon: 'contact_support', label: 'ติดต่อเรา' },
];

const isAboutActive = computed(() => {
  const aboutLink = navLinks.find(l => l.label === 'เกี่ยวกับเรา');
  if (!aboutLink || !aboutLink.children) return false;
  return aboutLink.children.some(child => router.currentRoute.value.path === child.to);
});

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

/* Dropdown animation */
.animation-scale-in {
  animation: scaleIn 0.2s ease-out;
}

@keyframes scaleIn {
  from {
    opacity: 0;
    transform: scale(0.95);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}

/* Respect reduced motion */
@media (prefers-reduced-motion: reduce) {
  .mobile-menu-enter-active,
  .mobile-menu-leave-active {
    transition: none;
  }
}
</style>
