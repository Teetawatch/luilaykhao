<template>
  <header class="sticky top-0 z-50 w-full">
    <!-- Trust Bar (Top Bar) -->
    <div 
      class="w-full bg-primary text-white overflow-hidden transform-gpu"
      :class="isScrolled ? 'h-0 max-h-0 opacity-0 border-none' : 'h-10 md:h-12 opacity-100 border-b border-white/5'"
      style="will-change: height, opacity; transition: height 0.4s ease, opacity 0.3s ease, max-height 0.4s ease;"
    >
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-full flex items-center justify-between animate-trust-bar">
        <!-- Left: License -->
        <div class="flex items-center gap-3 text-[12px] md:text-[14px] font-medium tracking-wide opacity-90 hover:opacity-100 transition-opacity">
          <div class="flex items-center gap-1.5">
            <span class="material-symbols-rounded text-[18px] md:text-[20px] text-accent-light filled-icon">verified_user</span>
            <span class="hidden sm:inline">ใบอนุญาตนำเที่ยวเลขที่ 12/03773</span>
            <span class="sm:hidden">ใบอนุญาต 12/03773</span>
          </div>
          <div class="hidden md:block h-3 w-[1px] bg-white/20 mx-1"></div>
          <div class="hidden lg:flex items-center gap-1.5 text-accent-light/80">
            <span class="material-symbols-rounded text-[16px]">verified</span>
            <span class="text-[11px] uppercase tracking-widest font-bold">ใบอนุญาตประกอบธุรกิจนำเที่ยว กรมการท่องเที่ยว</span>
          </div>
        </div>
        
        <!-- Right: Phone -->
        <div class="flex items-center gap-4">
          <a 
            href="tel:0626126006" 
            class="flex items-center gap-2 px-3 py-1 rounded-full bg-white/5 hover:bg-white/10 border border-white/10 hover:border-white/20 transition-all duration-300 group/phone"
            aria-label="โทรติดต่อสอบถาม 062-612-6006"
          >
            <span class="material-symbols-rounded text-[18px] text-accent-light group-hover/phone:rotate-12 transition-transform">call</span>
            <span class="text-[14px] md:text-[15px] font-bold tracking-tight">062-612-6006</span>
          </a>
        </div>
      </div>
    </div>

    <!-- Main Navbar -->
    <nav 
      class="navbar-root bg-white/95 backdrop-blur-md border-b border-sand-dark/30"
      :class="[isScrolled ? 'shadow-[0_10px_30px_-10px_rgba(0,0,0,0.1)] py-0' : 'shadow-sm py-1']"
      style="transition: box-shadow 0.4s ease, padding 0.4s ease;"
    >
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between transform-gpu" :class="isScrolled ? 'h-16' : 'h-20'" style="will-change: height; transition: height 0.4s ease;">

        <!-- Logo -->
        <router-link to="/" class="flex items-center gap-3 shrink-0 group">
          <div class="relative flex items-center justify-center w-14 h-14">
            <img src="/images/logo.png" alt="ลุยเลเขา Logo" class="w-14 h-14" />
          </div>
        </router-link>

        <!-- Desktop Menu (Right-aligned) -->
        <div class="hidden md:flex items-center justify-end flex-1 px-4 lg:px-8">
          <div class="flex items-center gap-1 lg:gap-2">
            <template v-for="link in navLinks" :key="link.label">
              <!-- Dropdown Menu -->
              <div v-if="link.children" ref="navDropdownRef" class="relative group/nav">
                <div 
                  class="nav-link flex items-center gap-1.5 px-4 py-2 rounded-full text-[14px] font-bold text-text-mid hover:text-primary hover:bg-primary/5 transition-all duration-300 cursor-pointer"
                  :class="{ 'active-state': isAboutActive }"
                  @click="navDropdownOpen = !navDropdownOpen"
                >
                  <span>{{ link.label }}</span>
                  <span class="material-symbols-rounded text-[18px] transition-transform duration-300 group-hover/nav:translate-y-0.5" :class="{ 'rotate-180': navDropdownOpen }">expand_more</span>
                </div>

                <!-- Dropdown List -->
                <div v-if="navDropdownOpen" class="absolute top-full left-1/2 -translate-x-1/2 w-64 pt-3 transition-all duration-300 transform z-[60] animation-fade-slide">
                  <div class="bg-white/98 backdrop-blur-xl rounded-2xl shadow-2xl border border-sand-dark/30 overflow-hidden p-1.5">
                    <router-link 
                      v-for="child in link.children" 
                      :key="child.to" 
                      :to="child.to"
                      @click="navDropdownOpen = false"
                      class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-[13px] font-bold text-text-mid hover:text-primary hover:bg-sand/50 transition-all duration-200"
                      active-class="bg-primary/5 text-primary"
                    >
                      <span class="material-symbols-rounded text-[18px] opacity-70 group-hover:opacity-100 transition-opacity" :class="{ 'filled-icon': router.currentRoute.value.path === child.to }">{{ child.icon }}</span>
                      {{ child.label }}
                    </router-link>
                  </div>
                </div>
              </div>

              <!-- Simple Link -->
              <router-link
                v-else
                :to="link.to"
                class="nav-link flex items-center gap-2 px-4 py-2 rounded-full text-[14px] font-bold text-text-mid hover:text-primary hover:bg-primary/5 transition-all duration-300 group/link"
                :exact="link.to === '/'"
              >
                <span>{{ link.label }}</span>
              </router-link>
            </template>
          </div>
        </div>

        <!-- Right Side Actions -->
        <div class="hidden md:flex items-center gap-5 lg:gap-6 shrink-0">
          
          <!-- Utility Actions (Search, Wishlist, Login) -->
          <div class="flex items-center gap-4">
            <!-- Search -->
            <div class="relative flex items-center transition-all duration-300">
              <button 
                v-if="!desktopSearchExpanded"
                @click="toggleDesktopSearch"
                class="flex items-center justify-center w-9 h-9 rounded-full text-text-mid hover:text-primary hover:bg-sand/50 transition-all duration-300"
                title="ค้นหา"
              >
                <span class="material-symbols-rounded text-[20px]">search</span>
              </button>
              
              <div v-else class="relative w-[180px] lg:w-[220px] flex items-center animate-in fade-in zoom-in-95 duration-300">
                <span class="material-symbols-rounded absolute left-3 text-[18px] text-primary">search</span>
                <input 
                  type="text" 
                  v-model="searchQuery" 
                  ref="desktopSearchInput"
                  @keyup.enter="doSearch(); desktopSearchExpanded = false"
                  @blur="!searchQuery && (desktopSearchExpanded = false)"
                  placeholder="ค้นหา..." 
                  class="w-full bg-sand/30 border-none rounded-full py-2 pl-9 pr-8 text-[12px] font-bold text-text-dark outline-none focus:ring-2 focus:ring-primary/20 transition-all"
                />
                <button 
                  @click="desktopSearchExpanded = false; searchQuery = ''" 
                  class="absolute right-2.5 w-6 h-6 flex items-center justify-center rounded-full bg-sand-dark/20 text-text-muted hover:text-red-500 transition-colors"
                >
                  <span class="material-symbols-rounded text-[14px]">close</span>
                </button>
              </div>
            </div>

            <!-- Wishlist (Only for logged in) -->
            <div v-if="auth.isLoggedIn" ref="wishlistDropdownRef" class="relative flex items-center">
              <button
                @click.stop="wishlistDropdownOpen = !wishlistDropdownOpen"
                class="flex items-center justify-center w-9 h-9 rounded-full text-text-mid hover:text-primary hover:bg-sand/50 transition-all"
              >
                <span class="material-symbols-rounded text-[20px]" :class="{ 'text-red-500': wishlistStore.favorites.length > 0 }" :style="wishlistStore.favorites.length > 0 ? wishlistFilledStyle : {}">favorite</span>
                <span v-if="wishlistStore.favorites.length > 0" class="absolute top-0 right-0 w-4 h-4 bg-red-500 text-white text-[9px] font-black rounded-full flex items-center justify-center border-2 border-white shadow-sm">
                  {{ wishlistStore.favorites.length }}
                </span>
              </button>

              <!-- Wishlist Dropdown -->
              <div v-if="wishlistDropdownOpen" class="absolute top-full right-0 w-80 pt-3 z-[60] animation-fade-slide">
                <div class="bg-white/98 backdrop-blur-xl rounded-2xl shadow-2xl border border-sand-dark/30 overflow-hidden">
                  <div class="px-5 py-3.5 bg-sand/30 border-b border-sand-dark/30 flex items-center gap-2">
                    <span class="material-symbols-rounded text-[18px] text-red-500 filled-icon">favorite</span>
                    <span class="text-[13px] font-bold text-text-dark">รายการโปรด</span>
                    <span class="ml-auto text-[11px] font-bold text-text-muted bg-sand px-2 py-0.5 rounded-full">{{ wishlistStore.favorites.length }}</span>
                  </div>
                  <div v-if="wishlistStore.favorites.length === 0" class="py-8 px-5 text-center">
                    <span class="material-symbols-rounded text-[40px] text-sand-dark/40">favorite_border</span>
                    <p class="mt-2 text-[12px] text-text-muted font-medium">ยังไม่มีรายการโปรด</p>
                  </div>
                  <div v-else class="max-h-64 overflow-y-auto">
                     <router-link
                      v-for="trip in wishlistStore.favorites"
                      :key="typeof trip === 'object' ? trip.id : trip"
                      :to="`/trips/${typeof trip === 'object' ? trip.id : trip}`"
                      @click="wishlistDropdownOpen = false"
                      class="flex items-center gap-3 px-4 py-2.5 hover:bg-sand/50 transition-all border-b border-sand-dark/10 last:border-0"
                    >
                      <div class="w-10 h-10 rounded-lg overflow-hidden shrink-0 bg-sand-dark/20">
                        <img v-if="trip.cover_image" :src="trip.cover_image" class="w-full h-full object-cover" />
                      </div>
                      <div class="flex-1 min-w-0">
                        <p class="text-[12px] font-bold text-text-dark truncate">{{ typeof trip === 'object' ? trip.title : `ทริป #${trip}` }}</p>
                        <p class="text-[10px] text-primary font-bold">฿{{ Number(trip.price || 0).toLocaleString() }}</p>
                      </div>
                    </router-link>
                  </div>
                  <div class="p-3 bg-sand/10">
                    <router-link to="/trips" @click="wishlistDropdownOpen = false" class="block w-full py-2 text-center text-[11px] font-bold text-primary bg-primary/5 hover:bg-primary/10 rounded-xl transition-all">ดูทริปทั้งหมด</router-link>
                  </div>
                </div>
              </div>
            </div>

            <!-- User / Login -->
            <template v-if="auth.isLoggedIn">
              <div ref="userDropdownRef" class="relative flex items-center">
                <button 
                  @click.stop="userDropdownOpen = !userDropdownOpen"
                  class="flex items-center gap-2 p-1 pr-3 rounded-full hover:bg-sand/50 transition-all border border-transparent hover:border-sand-dark/20"
                >
                  <div class="w-8 h-8 rounded-full bg-primary flex items-center justify-center overflow-hidden shadow-sm">
                    <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" class="w-full h-full object-cover" />
                    <span v-else class="text-white text-[12px] font-bold">{{ auth.userName?.charAt(0)?.toUpperCase() }}</span>
                  </div>
                  <span class="text-[13px] font-bold text-text-dark hidden lg:block">{{ auth.userName }}</span>
                  <span class="material-symbols-rounded text-[18px] text-text-muted transition-transform" :class="{ 'rotate-180': userDropdownOpen }">expand_more</span>
                </button>

                <div v-if="userDropdownOpen" class="absolute top-full right-0 w-64 pt-3 z-[60] animation-fade-slide">
                  <div class="bg-white/98 backdrop-blur-xl rounded-2xl shadow-2xl border border-sand-dark/30 overflow-hidden p-1.5">
                    <div class="px-4 py-3 border-b border-sand-dark/10 mb-1">
                      <p class="text-[14px] font-bold text-text-dark truncate">{{ auth.userName }}</p>
                      <p class="text-[10px] text-primary font-bold uppercase tracking-wider">{{ isAdmin ? 'Admin' : 'Member' }}</p>
                    </div>
                    <router-link to="/profile" @click="userDropdownOpen = false" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-[13px] font-bold text-text-mid hover:text-primary hover:bg-sand/50 transition-all">
                      <span class="material-symbols-rounded text-[20px]">account_circle</span>
                      ข้อมูลส่วนตัว
                    </router-link>
                    <router-link to="/my-bookings" @click="userDropdownOpen = false" class="flex items-center gap-3 px-4 py-2.5 rounded-xl text-[13px] font-bold text-text-mid hover:text-primary hover:bg-sand/50 transition-all">
                      <span class="material-symbols-rounded text-[20px]">confirmation_number</span>
                      การจองของฉัน
                    </router-link>
                    <div class="h-px bg-sand-dark/10 my-1 mx-2"></div>
                    <button @click="handleLogout" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl text-[13px] font-bold text-red-500 hover:bg-red-50 transition-all">
                      <span class="material-symbols-rounded text-[20px]">logout</span>
                      ออกจากระบบ
                    </button>
                  </div>
                </div>
              </div>
            </template>
            <template v-else>
              <router-link
                to="/login"
                class="flex items-center gap-2 px-2 py-1.5 text-[14px] font-medium text-text-mid hover:text-primary transition-all duration-150 group/login cursor-pointer"
              >
                <span class="material-symbols-rounded text-[20px] text-text-muted group-hover/login:text-primary group-hover/login:scale-105 transition-all duration-150">account_circle</span>
                <span class="hover:underline underline-offset-4 decoration-primary/30">เข้าสู่ระบบ</span>
              </router-link>
            </template>
          </div>

          <!-- Primary CTA Button -->
          <router-link
            to="/trips"
            class="flex items-center gap-2 bg-primary text-white px-5 py-2.5 rounded-full font-semibold text-[14px] shadow-md shadow-primary/10 hover:shadow-lg hover:shadow-primary/20 hover:bg-primary-dark hover:-translate-y-[1px] active:translate-y-0 active:scale-[0.98] transition-all duration-200 group"
          >
            <span class="material-symbols-rounded text-[20px] group-hover:rotate-12 transition-transform">explore</span>
            <span>จองทริป</span>
          </router-link>
        </div>

        <!-- Mobile menu button -->
        <div class="md:hidden flex items-center gap-2">
          <!-- Mobile search field toggle? - opting for integrated in menu below -->
          <button
            @click="mobileOpen = !mobileOpen"
            class="flex items-center justify-center w-11 h-11 rounded-full hover:bg-sand transition-colors duration-200 cursor-pointer focus:outline-none"
            aria-label="Toggle menu"
          >
            <span class="material-symbols-rounded text-[24px] text-text-dark transition-transform duration-300" :class="{ 'rotate-180 scale-90': mobileOpen }">
              {{ mobileOpen ? 'close' : 'menu' }}
            </span>
          </button>
        </div>
      </div>
    </div>


    <!-- Mobile Menu -->
    <Transition name="mobile-menu">
      <div v-if="mobileOpen" class="md:hidden bg-white/95 backdrop-blur-xl border-t border-sand-dark/40 absolute w-full shadow-lg">
        <div class="max-w-7xl mx-auto px-4 py-6 space-y-4 max-h-[calc(100vh-5rem)] overflow-y-auto">

          <!-- Mobile Search Bar -->
          <div class="relative group w-full mb-2">
            <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-[20px] text-text-muted transition-colors">search</span>
            <input 
              type="text" 
              v-model="searchQuery" 
              @keyup.enter="doSearch"
              placeholder="ค้นหาทริปที่คุณต้องการ..." 
              class="w-full bg-sand/60 border border-sand-dark/40 rounded-2xl py-3.5 pl-12 pr-4 text-sm font-bold text-text-dark placeholder:text-text-muted/60 focus:bg-white focus:border-primary outline-none transition-all"
            />
          </div>

          <div class="space-y-2">

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
                <span class="material-symbols-rounded text-[20px] transition-transform duration-200 group-active:scale-90">{{ child.icon }}</span>
                {{ child.label }}
              </router-link>
            </div>
            
            <router-link
              v-else
              :to="link.to"
              @click="mobileOpen = false"
              class="mobile-nav-link flex items-center gap-3.5 px-5 py-3.5 rounded-2xl text-base font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200 active:scale-[0.98]"
              :exact="link.to === '/'"
            >
              <span class="material-symbols-rounded text-[22px]">{{ link.icon }}</span>
              {{ link.label }}
            </router-link>
          </template>

          <template v-if="auth.isLoggedIn">
            <div class="w-full h-px bg-sand-dark/60 my-3"></div>
            <router-link
              to="/trips"
              @click="mobileOpen = false"
              class="flex items-center gap-3.5 px-5 py-3.5 rounded-2xl text-base font-semibold hover:text-primary hover:bg-sand transition-all duration-200 active:scale-[0.98]"
              :class="wishlistStore.favorites.length > 0 ? 'text-red-500' : 'text-text-mid'"
            >
              <span class="material-symbols-rounded text-[22px]" :style="wishlistStore.favorites.length > 0 ? wishlistFilledStyle : {}">favorite</span>
              <span class="flex-1">รายการโปรด</span>
              <span v-if="wishlistStore.favorites.length > 0" class="bg-red-500 text-white text-xs font-bold rounded-full px-2.5 py-1 shadow-sm border border-white/20">{{ wishlistStore.favorites.length }}</span>
            </router-link>
            
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
              v-if="isStaff"
              to="/my-staff-trips"
              @click="mobileOpen = false"
              class="flex items-center gap-3.5 px-5 py-3.5 rounded-2xl text-base font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200 active:scale-[0.98]"
            >
              <span class="material-symbols-rounded text-[22px]">badge</span>
              ตารางงานสตาฟ
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
    </div>
    </Transition>
    </nav>
  </header>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useWishlistStore } from '../stores/wishlist';
import api from '../lib/axios';

const auth = useAuthStore();
const wishlistStore = useWishlistStore();
const router = useRouter();
const mobileOpen = ref(false);
const searchQuery = ref('');
const unreadNotifications = ref(0);
const isScrolled = ref(false);
const navDropdownOpen = ref(false);
const userDropdownOpen = ref(false);
const wishlistDropdownOpen = ref(false);
const navDropdownRef = ref(null);
const userDropdownRef = ref(null);
const wishlistDropdownRef = ref(null);
const desktopSearchInput = ref(null);
const desktopSearchExpanded = ref(false);
const wishlistFilledStyle = { fontVariationSettings: "'FILL' 1" };

function toggleDesktopSearch() {
  desktopSearchExpanded.value = !desktopSearchExpanded.value;
  if (desktopSearchExpanded.value) {
    setTimeout(() => {
      desktopSearchInput.value?.focus();
    }, 100);
  }
}

function handleClickOutside(e) {
  const navEl = Array.isArray(navDropdownRef.value)
    ? navDropdownRef.value[0]
    : navDropdownRef.value;
  const userEl = Array.isArray(userDropdownRef.value)
    ? userDropdownRef.value[0]
    : userDropdownRef.value;
  const wishlistEl = Array.isArray(wishlistDropdownRef.value)
    ? wishlistDropdownRef.value[0]
    : wishlistDropdownRef.value;
  if (!navEl || !navEl.contains(e.target)) {
    navDropdownOpen.value = false;
  }
  if (!userEl || !userEl.contains(e.target)) {
    userDropdownOpen.value = false;
  }
  if (!wishlistEl || !wishlistEl.contains(e.target)) {
    wishlistDropdownOpen.value = false;
  }
}

function doSearch() {
  if (!searchQuery.value.trim()) return;
  router.push(`/trips?search=${encodeURIComponent(searchQuery.value.trim())}`);
  mobileOpen.value = false;
  searchQuery.value = '';
}

async function fetchUnreadCount() {
  if (!auth.isLoggedIn) return;
  try {
    const res = await api.get('/notifications/unread-count');
    unreadNotifications.value = res.data.data.count;
  } catch {}
}

let pollInterval = null;
function handleScroll() {
  // Hysteresis: use different thresholds for scroll-down vs scroll-up
  // to prevent jitter from header height changes shifting the scroll position
  const scrollY = window.scrollY;
  if (!isScrolled.value && scrollY > 100) {
    isScrolled.value = true;
  } else if (isScrolled.value && scrollY < 10) {
    isScrolled.value = false;
  }
}

onMounted(() => {
  fetchUnreadCount();
  pollInterval = setInterval(fetchUnreadCount, 60000);
  window.addEventListener('scroll', handleScroll);
  document.addEventListener('click', handleClickOutside);
  document.addEventListener('touchstart', handleClickOutside);
});
onUnmounted(() => {
  clearInterval(pollInterval);
  window.removeEventListener('scroll', handleScroll);
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
      { to: '/reviews', icon: 'reviews', label: 'รีวิวลูกค้า' },
      { to: '/how-to-book', icon: 'menu_book', label: 'วิธีการจอง' },
      { to: '/faq', icon: 'quiz', label: 'FAQ' },
      { to: '/terms', icon: 'gavel', label: 'เงื่อนไขการให้บริการ' },
      { to: '/privacy', icon: 'policy', label: 'นโยบายความเป็นส่วนตัว' },
    ]
  },
  { to: '/trips', icon: 'explore', label: 'กิจกรรม' },
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

const isStaff = computed(() => {
  const roles = auth.user?.roles?.map(r => typeof r === 'string' ? r : r.name) || [];
  return roles.includes('staff');
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
.nav-link.router-link-active,
.nav-link.router-link-exact-active,
.nav-link.active-state,
.mobile-nav-link.router-link-active,
.mobile-nav-link.router-link-exact-active {
  color: var(--color-primary) !important;
  background-color: rgba(var(--color-primary-rgb), 0.08) !important;
}

.nav-link.router-link-active span.material-symbols-rounded,
.mobile-nav-link.router-link-active span.material-symbols-rounded,
.filled-icon {
  font-variation-settings: 'FILL' 1 !important;
}

/* Glassmorphism for dropdowns */
.bg-white\/98 {
  background-color: rgba(255, 255, 255, 0.98);
}

/* Dropdown animation with fade and slide */
.animation-fade-slide {
  animation: fadeSlide 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes fadeSlide {
  from {
    opacity: 0;
    transform: translateY(-8px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* Icon Hover Micro-interactions */
.nav-link {
  transition: all 0.3s ease;
}

.nav-link span.material-symbols-rounded {
  display: inline-block;
  transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.nav-link:hover span.material-symbols-rounded:not(.filled-icon) {
  font-variation-settings: 'FILL' 0;
}

/* Trust Bar Animation */
@keyframes slideDownTrust {
  from { transform: translateY(-100%); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}
.animate-trust-bar {
  animation: slideDownTrust 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

/* Respect reduced motion */
@media (prefers-reduced-motion: reduce) {
  .mobile-menu-enter-active,
  .mobile-menu-leave-active,
  .animate-trust-bar {
    transition: none;
    animation: none;
  }
}
</style>
