<template>
  <nav class="navbar-root sticky top-0 z-50 bg-white/90 backdrop-blur-md border-b border-sand-dark/50 shadow-sm transition-all duration-300">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-20">

        <!-- Logo -->
        <router-link to="/" class="flex items-center gap-3 shrink-0 group">
          <div class="relative flex items-center justify-center w-14 h-14">
            <img src="/images/logo.png" alt="ลุยเลเขา Logo" class="w-14 h-14" />
          </div>
        </router-link>

        <!-- Desktop Menu -->
        <div class="hidden md:flex items-center flex-1 justify-end gap-6">
          <div class="flex items-center gap-2">
            <div class="flex items-center gap-1 mr-4 h-full">
              <template v-for="link in navLinks" :key="link.label">
                <!-- Dropdown Menu -->
                <div v-if="link.children" ref="navDropdownRef" class="relative h-full flex items-center">
                  <div 
                    class="nav-link flex items-center gap-2 px-4 py-2.5 rounded-full text-sm font-semibold text-text-mid hover:text-primary hover:bg-sand transition-all duration-200 cursor-pointer"
                    :class="{ 'text-primary bg-sand': isAboutActive }"
                    @click="navDropdownOpen = !navDropdownOpen"
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

            <!-- Desktop Search Bar -->
            <div class="hidden lg:flex items-center relative group max-w-[240px] w-full transition-all duration-300 focus-within:max-w-[300px] mr-2">
              <span class="material-symbols-rounded absolute left-3.5 text-[18px] text-text-muted group-focus-within:text-primary transition-colors">search</span>
              <input 
                type="text" 
                v-model="searchQuery" 
                @keyup.enter="doSearch"
                placeholder="ค้นหา..." 
                class="w-full bg-sand/50 border border-sand-dark/40 rounded-full py-2 pl-9 pr-4 text-[12px] font-bold text-text-dark placeholder:text-text-muted/60 focus:bg-white focus:border-primary focus:ring-4 focus:ring-primary/5 outline-none transition-all"
              />
            </div>

            <!-- Wishlist Button (Desktop) -->
            <div v-if="auth.isLoggedIn" ref="wishlistDropdownRef" class="relative h-full flex items-center">
              <button
                @click.stop="wishlistDropdownOpen = !wishlistDropdownOpen"
                class="relative flex items-center justify-center w-10 h-10 rounded-full hover:bg-sand transition-colors duration-200 text-text-mid hover:text-primary cursor-pointer"
                aria-label="รายการที่ชอบ"
              >
                <span class="material-symbols-rounded text-[22px]" :class="wishlistStore.favorites.length > 0 ? 'text-red-500' : ''" :style="wishlistStore.favorites.length > 0 ? wishlistFilledStyle : {}">favorite</span>
                <span v-if="wishlistStore.favorites.length > 0" class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-red-500 text-white text-[9px] font-black rounded-full flex items-center justify-center">
                  {{ wishlistStore.favorites.length > 9 ? '9+' : wishlistStore.favorites.length }}
                </span>
              </button>

              <!-- Wishlist Dropdown -->
              <div v-if="wishlistDropdownOpen" class="absolute top-full right-0 w-80 pt-2 z-[60] animation-scale-in">
                <div class="bg-white/95 backdrop-blur-xl rounded-2xl shadow-xl border border-sand-dark/50 overflow-hidden">
                  <div class="px-5 py-3.5 bg-sand/40 border-b border-sand-dark/40 flex items-center gap-2">
                    <span class="material-symbols-rounded text-[18px] text-red-500" style="font-variation-settings:'FILL' 1">favorite</span>
                    <span class="text-[13px] font-bold text-text-dark">รายการที่ชอบ</span>
                    <span class="ml-auto text-[11px] font-bold text-text-muted bg-sand px-2 py-0.5 rounded-full">{{ wishlistStore.favorites.length }} รายการ</span>
                  </div>
                  <div v-if="wishlistStore.favorites.length === 0" class="flex flex-col items-center gap-2 py-8 px-5">
                    <span class="material-symbols-rounded text-[40px] text-sand-dark/60">favorite_border</span>
                    <p class="text-[13px] text-text-muted font-medium text-center">ยังไม่มีรายการที่ชอบ<br/>กดหัวใจที่ทริปที่คุณสนใจได้เลย</p>
                  </div>
                  <div v-else class="max-h-72 overflow-y-auto py-1.5">
                    <router-link
                      v-for="trip in wishlistStore.favorites"
                      :key="typeof trip === 'object' ? trip.id : trip"
                      :to="`/trips/${typeof trip === 'object' ? trip.id : trip}`"
                      @click="wishlistDropdownOpen = false"
                      class="flex items-center gap-3.5 px-4 py-2.5 hover:bg-sand transition-all group"
                    >
                      <div class="w-10 h-10 rounded-xl overflow-hidden shrink-0 bg-sand-dark/20">
                        <img v-if="trip.cover_image" :src="trip.cover_image" class="w-full h-full object-cover" />
                        <span v-else class="material-symbols-rounded text-[20px] text-sand-dark/50 flex items-center justify-center w-full h-full">image</span>
                      </div>
                      <div class="flex-1 min-w-0">
                        <p class="text-[13px] font-bold text-text-dark truncate group-hover:text-primary">{{ typeof trip === 'object' ? trip.title : `ทริป #${trip}` }}</p>
                        <p v-if="trip.price" class="text-[11px] text-text-muted font-semibold">฿{{ Number(trip.price).toLocaleString() }}</p>
                      </div>
                      <button
                        @click.prevent.stop="wishlistStore.toggleFavorite(trip)"
                        class="shrink-0 w-7 h-7 flex items-center justify-center rounded-full text-red-400 hover:bg-red-50 hover:text-red-600 transition-all"
                        aria-label="ลบออก"
                      >
                        <span class="material-symbols-rounded text-[16px]">close</span>
                      </button>
                    </router-link>
                  </div>
                  <div v-if="wishlistStore.favorites.length > 0" class="px-4 py-3 border-t border-sand-dark/40">
                    <router-link to="/trips" @click="wishlistDropdownOpen = false" class="flex items-center justify-center gap-2 w-full py-2 rounded-xl bg-primary/10 text-primary text-[12px] font-bold hover:bg-primary/20 transition-all">
                      <span class="material-symbols-rounded text-[16px]">explore</span>
                      ดูกิจกรรมทั้งหมด
                    </router-link>
                  </div>
                </div>
              </div>
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

                      <router-link v-if="isStaff" to="/my-staff-trips" @click="userDropdownOpen = false" class="flex items-center gap-3.5 px-5 py-3 text-[13px] font-bold text-text-mid hover:text-primary hover:bg-sand transition-all border-l-4 border-transparent hover:border-primary">
                        <span class="material-symbols-rounded text-[20px] text-cyan-700">badge</span>
                        ตารางงานสตาฟ
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
              to="/trips"
              @click="mobileOpen = false"
              class="flex items-center gap-3.5 px-5 py-3.5 rounded-2xl text-base font-semibold hover:text-primary hover:bg-sand transition-all duration-200 active:scale-[0.98]"
              :class="wishlistStore.favorites.length > 0 ? 'text-red-500' : 'text-text-mid'"
            >
              <span class="material-symbols-rounded text-[22px]" :style="wishlistStore.favorites.length > 0 ? wishlistFilledStyle : {}">favorite</span>
              <span class="flex-1">รายการที่ชอบ</span>
              <span v-if="wishlistStore.favorites.length > 0" class="bg-red-500 text-white text-xs font-bold rounded-full px-2.5 py-1">{{ wishlistStore.favorites.length }}</span>
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
const navDropdownOpen = ref(false);
const userDropdownOpen = ref(false);
const wishlistDropdownOpen = ref(false);
const navDropdownRef = ref(null);
const userDropdownRef = ref(null);
const wishlistDropdownRef = ref(null);
const wishlistFilledStyle = { fontVariationSettings: "'FILL' 1" };

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
