<template>
  <header class="sticky top-0 z-50 w-full">
    <!-- Trust Bar (Top Bar) -->
    <div
      class="w-full bg-gradient-to-r from-primary via-primary-mid to-primary text-white overflow-hidden transform-gpu"
      :class="isScrolled ? 'h-0 max-h-0 opacity-0 border-none' : 'h-10 md:h-11 opacity-100 border-b border-white/5'"
      style="will-change: height, opacity; transition: height 0.4s ease, opacity 0.3s ease, max-height 0.4s ease;"
    >
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-full flex items-center justify-between gap-4 animate-trust-bar">
        <div class="flex items-center gap-2 text-[12px] md:text-[13px] font-semibold tracking-wide">
          <span class="material-symbols-rounded text-[18px] text-accent-light filled-icon">verified_user</span>
          <span class="hidden sm:inline">ใบอนุญาตนำเที่ยวเลขที่ 12/03773</span>
          <span class="sm:hidden">ใบอนุญาต 12/03773</span>
        </div>

        <div class="hidden lg:flex items-center gap-1.5 text-white/70">
          <span class="material-symbols-rounded text-[15px]">verified</span>
          <span class="text-[10px] uppercase tracking-[0.18em] font-bold">ใบอนุญาตประกอบธุรกิจนำเที่ยว กรมการท่องเที่ยว</span>
        </div>
      </div>
    </div>

    <!-- Main Navbar -->
    <nav
      class="navbar-root border-b transition-[background-color,box-shadow,border-color] duration-500"
      :class="isScrolled
        ? 'bg-white/80 supports-[backdrop-filter]:backdrop-blur-xl border-sand-dark/50'
        : 'bg-white border-sand-dark/30'"
    >
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div
          class="flex items-center gap-2 transform-gpu"
          :class="isScrolled ? 'h-16' : 'h-20'"
          style="will-change: height; transition: height 0.4s ease;"
        >
          <!-- Brand -->
          <router-link to="/" class="flex items-center gap-2.5 shrink-0 group rounded-2xl focus-ring">
            <img
              src="/images/logo.png?v=2"
              alt="ลุยเลเขา"
              class="object-contain transition-transform duration-500 group-hover:scale-105"
              :class="isScrolled ? 'w-11 h-11' : 'w-13 h-13'"
            />
            <span class="hidden sm:flex flex-col leading-none">
              <span class="text-[19px] font-black tracking-tight text-primary">ลุยเลเขา</span>
              <span class="mt-1 text-[9px] font-bold uppercase tracking-[0.24em] text-text-muted">Trip &amp; Travel</span>
            </span>
          </router-link>

          <!-- Desktop Nav (left-adjacent to brand, with gliding pill indicator) -->
          <div
            ref="navDropdownRef"
            class="hidden lg:flex flex-1 items-center min-w-0"
          >
            <div
              ref="navListRef"
              class="relative flex items-center ml-1 xl:ml-6"
              @mouseleave="hoveredNavLabel = null"
            >
              <!-- Gliding pill -->
              <span
                aria-hidden="true"
                class="nav-pill absolute top-1/2 -translate-y-1/2 h-9 rounded-full bg-primary/[0.07]"
                :style="{ left: `${indicator.left}px`, width: `${indicator.width}px`, opacity: indicator.opacity }"
              ></span>

              <template v-for="link in desktopNavLinks" :key="link.label">
                <!-- Dropdown / Mega menu -->
                <div
                  v-if="link.children"
                  class="relative"
                  @mouseenter="onNavItemHover(link.label)"
                  @mouseleave="onDropdownLeave"
                >
                  <button
                    type="button"
                    :ref="el => setNavItemRef(link.label, el)"
                    class="nav-link relative z-10 flex items-center gap-0.5 px-2.5 xl:px-4 py-2 rounded-full text-[14px] xl:text-[15px] font-semibold text-text-mid hover:text-primary transition-colors duration-200 cursor-pointer focus-ring"
                    :class="{ 'is-active': activeNavLabel === link.label }"
                    :aria-expanded="openNavDropdown === link.label"
                    aria-haspopup="true"
                    @click.stop="toggleNavDropdown(link.label)"
                  >
                    <span>{{ link.label }}</span>
                    <span
                      class="material-symbols-rounded text-[18px] transition-transform duration-300"
                      :class="{ 'rotate-180': openNavDropdown === link.label }"
                    >expand_more</span>
                  </button>

                  <!-- Panel -->
                  <div
                    v-if="openNavDropdown === link.label"
                    class="absolute top-full pt-3 z-[60] animation-fade-slide"
                    :class="isMegaMenu(link)
                      ? 'left-0 w-[min(600px,calc(100vw-3rem))]'
                      : 'left-1/2 -translate-x-1/2 w-[min(360px,calc(100vw-3rem))]'"
                  >
                    <div class="bg-white/95 supports-[backdrop-filter]:backdrop-blur-xl rounded-2xl ring-1 ring-primary/5 border border-sand-dark/40 overflow-hidden">
                      <div class="grid gap-1 p-2" :class="isMegaMenu(link) ? 'grid-cols-2' : 'grid-cols-1'">
                        <template v-for="child in link.children" :key="child.to">
                          <component
                            :is="child.external ? 'a' : 'router-link'"
                            v-bind="child.external ? { href: child.to } : { to: child.to }"
                            @click="openNavDropdown = null"
                            class="menu-item group/item flex items-start gap-3 rounded-xl px-3 py-2.5 transition-colors duration-200 hover:bg-sand focus-ring"
                            :class="{ 'bg-sand': !child.external && route.path === child.to }"
                          >
                            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-sand text-primary transition-colors duration-200 group-hover/item:bg-accent group-hover/item:text-white">
                              <span class="material-symbols-rounded text-[19px]">{{ child.icon }}</span>
                            </span>
                            <span class="min-w-0">
                              <span class="block text-[14px] font-bold text-text-dark transition-colors duration-200 group-hover/item:text-accent">{{ child.label }}</span>
                              <span v-if="child.desc" class="mt-0.5 block text-[11.5px] leading-snug text-text-muted">{{ child.desc }}</span>
                            </span>
                          </component>
                        </template>
                      </div>

                      <div v-if="isMegaMenu(link)" class="flex items-center justify-between gap-3 border-t border-sand-dark/40 bg-sand/40 px-4 py-3">
                        <p class="text-[12px] font-semibold text-text-muted">มีคำถามเพิ่มเติม? ทีมงานยินดีช่วยเหลือ</p>
                        <router-link
                          to="/contact"
                          @click="openNavDropdown = null"
                          class="shrink-0 inline-flex items-center gap-1.5 rounded-full bg-primary/5 px-3 py-1.5 text-[12px] font-bold text-primary transition-colors hover:bg-primary/10 focus-ring"
                        >
                          ติดต่อเรา
                          <span class="material-symbols-rounded text-[15px]">arrow_forward</span>
                        </router-link>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- External link (server-rendered page, full load) -->
                <a
                  v-else-if="link.external"
                  :ref="el => setNavItemRef(link.label, el)"
                  :href="link.to"
                  class="nav-link relative z-10 px-2.5 xl:px-4 py-2 rounded-full text-[14px] xl:text-[15px] font-semibold text-text-mid hover:text-primary transition-colors duration-200 focus-ring"
                  :class="{ 'is-active': activeNavLabel === link.label }"
                  @mouseenter="onNavItemHover(link.label)"
                >{{ link.label }}</a>

                <!-- Simple link -->
                <router-link
                  v-else
                  :ref="el => setNavItemRef(link.label, el)"
                  :to="link.to"
                  class="nav-link relative z-10 px-2.5 xl:px-4 py-2 rounded-full text-[14px] xl:text-[15px] font-semibold text-text-mid hover:text-primary transition-colors duration-200 focus-ring"
                  :class="{ 'is-active': activeNavLabel === link.label }"
                  @mouseenter="onNavItemHover(link.label)"
                >{{ link.label }}</router-link>
              </template>
            </div>
          </div>

          <!-- Desktop Actions -->
          <div class="hidden lg:flex items-center gap-1.5 lg:gap-2 shrink-0">
            <!-- Search -->
            <div ref="desktopSearchRef" class="relative flex items-center">
              <button
                type="button"
                aria-label="ค้นหาทริป"
                :aria-expanded="desktopSearchExpanded"
                class="icon-btn"
                :class="desktopSearchExpanded ? 'text-primary bg-sand' : ''"
                @click.stop="toggleDesktopSearch"
              >
                <span class="material-symbols-rounded text-[21px]">{{ desktopSearchExpanded ? 'close' : 'search' }}</span>
              </button>

              <div v-if="desktopSearchExpanded" class="absolute top-full right-0 w-[320px] pt-3 z-[60] animation-fade-slide">
                <div class="bg-white/95 supports-[backdrop-filter]:backdrop-blur-xl rounded-2xl ring-1 ring-primary/5 border border-sand-dark/40 p-2.5">
                  <div class="relative flex items-center">
                    <span class="material-symbols-rounded absolute left-3 text-[18px] text-primary">search</span>
                    <input
                      ref="desktopSearchInput"
                      v-model="searchQuery"
                      type="text"
                      placeholder="ค้นหาทริป..."
                      class="w-full bg-sand rounded-full py-2.5 pl-10 pr-9 text-[14px] font-semibold text-text-dark outline-none ring-1 ring-transparent focus:ring-accent/40 transition"
                      @keyup.enter="doSearch(); desktopSearchExpanded = false"
                    />
                    <button
                      v-if="searchQuery"
                      type="button"
                      aria-label="ล้างคำค้นหา"
                      class="absolute right-2.5 w-6 h-6 flex items-center justify-center rounded-full bg-sand-dark text-text-muted hover:text-red-500 transition-colors"
                      @click="searchQuery = ''"
                    >
                      <span class="material-symbols-rounded text-[14px]">close</span>
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Wishlist -->
            <div v-if="auth.isLoggedIn" ref="wishlistDropdownRef" class="relative flex items-center">
              <button
                type="button"
                aria-label="รายการโปรด"
                class="icon-btn"
                :class="wishlistDropdownOpen ? 'text-primary bg-sand' : ''"
                @click.stop="wishlistDropdownOpen = !wishlistDropdownOpen; notificationDropdownOpen = false; userDropdownOpen = false"
              >
                <span
                  class="material-symbols-rounded text-[21px]"
                  :class="{ 'text-red-500': wishlistStore.favorites.length > 0 }"
                  :style="wishlistStore.favorites.length > 0 ? wishlistFilledStyle : {}"
                >favorite</span>
                <span v-if="wishlistStore.favorites.length > 0" class="badge">{{ wishlistStore.favorites.length }}</span>
              </button>

              <div v-if="wishlistDropdownOpen" class="absolute top-full right-0 w-80 pt-3 z-[60] animation-fade-slide">
                <div class="bg-white/95 supports-[backdrop-filter]:backdrop-blur-xl rounded-2xl ring-1 ring-primary/5 border border-sand-dark/40 overflow-hidden">
                  <div class="px-5 py-3.5 bg-sand/50 border-b border-sand-dark/40 flex items-center gap-2">
                    <span class="material-symbols-rounded text-[18px] text-red-500 filled-icon">favorite</span>
                    <span class="text-[13px] font-bold text-text-dark">รายการโปรด</span>
                    <span class="ml-auto text-[11px] font-bold text-text-muted bg-sand px-2 py-0.5 rounded-full">{{ wishlistStore.favorites.length }}</span>
                  </div>
                  <div v-if="wishlistStore.favorites.length === 0" class="py-8 px-5 text-center">
                    <span class="material-symbols-rounded text-[40px] text-sand-dark">favorite_border</span>
                    <p class="mt-2 text-[12px] text-text-muted font-medium">ยังไม่มีรายการโปรด</p>
                  </div>
                  <div v-else class="max-h-64 overflow-y-auto">
                    <router-link
                      v-for="trip in wishlistStore.favorites"
                      :key="typeof trip === 'object' ? trip.id : trip"
                      :to="`/trips/${typeof trip === 'object' ? trip.id : trip}`"
                      class="flex items-center gap-3 px-4 py-2.5 hover:bg-sand transition-colors border-b border-sand-dark/40 last:border-0"
                      @click="wishlistDropdownOpen = false"
                    >
                      <div class="w-10 h-10 rounded-lg overflow-hidden shrink-0 bg-sand-dark">
                        <img v-if="trip.thumbnail_image || trip.cover_image" :src="trip.thumbnail_image || trip.cover_image" class="w-full h-full object-cover" />
                      </div>
                      <div class="flex-1 min-w-0">
                        <p class="text-[12px] font-bold text-text-dark truncate">{{ typeof trip === 'object' ? trip.title : `ทริป #${trip}` }}</p>
                        <p class="text-[10px] text-primary font-bold">฿{{ Number(trip.price || 0).toLocaleString() }}</p>
                      </div>
                    </router-link>
                  </div>
                  <div class="p-3 bg-sand/30">
                    <router-link to="/trips" class="block w-full py-2 text-center text-[11px] font-bold text-primary bg-primary/5 hover:bg-primary/10 rounded-xl transition-colors" @click="wishlistDropdownOpen = false">ดูทริปทั้งหมด</router-link>
                  </div>
                </div>
              </div>
            </div>

            <!-- Notifications -->
            <div v-if="auth.isLoggedIn" ref="notificationDropdownRef" class="relative flex items-center">
              <button
                type="button"
                aria-label="การแจ้งเตือน"
                class="icon-btn"
                :class="notificationDropdownOpen ? 'text-primary bg-sand' : ''"
                @click.stop="toggleNotificationDropdown"
              >
                <span class="material-symbols-rounded text-[21px]" :class="{ 'filled-icon': unreadNotifications > 0 }">notifications</span>
                <span v-if="unreadNotifications > 0" class="badge">{{ unreadNotifications }}</span>
              </button>

              <div v-if="notificationDropdownOpen" class="absolute top-full right-0 w-[420px] pt-3 z-[60] animation-fade-slide">
                <div class="bg-white/95 supports-[backdrop-filter]:backdrop-blur-xl rounded-2xl ring-1 ring-primary/5 border border-sand-dark/40 overflow-hidden">
                  <div class="px-5 py-4 bg-sand/50 border-b border-sand-dark/40">
                    <div class="flex items-start gap-3">
                      <div class="w-10 h-10 rounded-2xl bg-primary/10 text-primary flex items-center justify-center shrink-0">
                        <span class="material-symbols-rounded text-[22px]" :class="{ 'filled-icon': unreadNotifications > 0 }">notifications</span>
                      </div>
                      <div class="flex-1 min-w-0">
                        <p class="text-[14px] font-black text-text-dark leading-tight">การแจ้งเตือน</p>
                        <p class="text-[11px] font-semibold text-text-muted mt-1">
                          {{ unreadNotifications > 0 ? `ยังไม่ได้อ่าน ${unreadNotifications} รายการ` : 'อ่านครบทุกข้อความแล้ว' }}
                        </p>
                      </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2 mt-4">
                      <button
                        type="button"
                        :disabled="unreadNotifications === 0 || notificationBusy"
                        class="flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl text-[11px] font-black bg-primary/10 text-primary hover:bg-primary/15 disabled:opacity-45 disabled:cursor-not-allowed transition-colors"
                        @click="markAllNotificationsRead"
                      >
                        <span class="material-symbols-rounded text-[17px]">done_all</span>
                        อ่านทั้งหมด
                      </button>
                      <button
                        type="button"
                        :disabled="notifications.length === 0 || notificationBusy"
                        class="flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl text-[11px] font-black bg-red-50 text-red-500 hover:bg-red-100 disabled:opacity-45 disabled:cursor-not-allowed transition-colors"
                        @click="clearNotifications"
                      >
                        <span class="material-symbols-rounded text-[17px]">delete_sweep</span>
                        เคลียร์ข้อความ
                      </button>
                    </div>
                  </div>

                  <div v-if="notificationsLoading" class="py-10 px-5 text-center">
                    <div class="w-8 h-8 mx-auto border-4 border-primary/20 border-t-primary rounded-full animate-spin"></div>
                    <p class="mt-3 text-[12px] font-bold text-text-muted">กำลังโหลดการแจ้งเตือน...</p>
                  </div>

                  <div v-else-if="notificationError" class="py-10 px-5 text-center">
                    <span class="material-symbols-rounded text-[36px] text-red-300">error</span>
                    <p class="mt-2 text-[12px] font-bold text-text-muted">{{ notificationError }}</p>
                    <button type="button" class="mt-3 px-4 py-2 rounded-xl text-[11px] font-black text-primary bg-primary/5 hover:bg-primary/10 transition-colors" @click="loadNotifications">ลองใหม่</button>
                  </div>

                  <div v-else-if="notifications.length === 0" class="py-10 px-5 text-center">
                    <span class="material-symbols-rounded text-[42px] text-sand-dark">notifications_off</span>
                    <p class="mt-2 text-[13px] font-black text-text-dark">ยังไม่มีการแจ้งเตือน</p>
                    <p class="mt-1 text-[11px] font-semibold text-text-muted">ข้อความใหม่จะแสดงในส่วนนี้</p>
                  </div>

                  <div v-else class="max-h-[440px] overflow-y-auto divide-y divide-sand-dark/40">
                    <div
                      v-for="n in notifications"
                      :key="n.id"
                      class="relative px-4 py-3.5 hover:bg-sand/60 transition-colors"
                      :class="{ 'bg-primary/[0.035]': !n.is_read }"
                    >
                      <div v-if="!n.is_read" class="absolute left-0 top-0 bottom-0 w-1 bg-primary"></div>
                      <div class="flex gap-3">
                        <div class="w-10 h-10 rounded-2xl flex items-center justify-center shrink-0" :class="notifStyle(n.type).bg">
                          <span class="material-symbols-rounded text-[22px]" :class="notifStyle(n.type).text">{{ notifIcon(n.type) }}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                          <div class="flex items-start gap-3">
                            <div class="flex-1 min-w-0">
                              <p class="text-[13px] font-black text-text-dark leading-snug" :class="{ 'text-primary': !n.is_read }">{{ n.title }}</p>
                              <p class="mt-1 text-[12px] font-medium text-text-mid leading-relaxed whitespace-normal break-words">{{ n.body }}</p>
                            </div>
                            <button
                              type="button"
                              title="ลบข้อความนี้"
                              aria-label="ลบข้อความนี้"
                              class="w-8 h-8 rounded-full flex items-center justify-center text-text-muted hover:text-red-500 hover:bg-red-50 transition-colors shrink-0"
                              @click="deleteNotification(n.id)"
                            >
                              <span class="material-symbols-rounded text-[18px]">close</span>
                            </button>
                          </div>

                          <div class="mt-3 flex flex-wrap items-center gap-1.5">
                            <span class="px-2 py-1 rounded-full text-[10px] font-black bg-sand text-text-muted">{{ notificationTypeLabel(n.type) }}</span>
                            <span class="px-2 py-1 rounded-full text-[10px] font-black" :class="n.is_read ? 'bg-sand text-text-muted' : 'bg-primary/10 text-primary'">
                              {{ n.is_read ? 'อ่านแล้ว' : 'ยังไม่ได้อ่าน' }}
                            </span>
                            <span class="px-2 py-1 rounded-full text-[10px] font-black bg-sand text-text-muted">{{ timeAgo(n.created_at) }}</span>
                          </div>

                          <div v-if="notificationDataEntries(n.data).length" class="mt-2 flex flex-wrap gap-1.5">
                            <span
                              v-for="item in notificationDataEntries(n.data)"
                              :key="`${n.id}-${item.key}`"
                              class="max-w-full px-2 py-1 rounded-lg bg-white border border-sand-dark text-[10px] font-bold text-text-mid break-all"
                            >
                              {{ item.label }}: {{ item.value }}
                            </span>
                          </div>

                          <div v-if="!n.is_read" class="mt-3">
                            <button
                              type="button"
                              class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-[10px] font-black text-primary bg-primary/5 hover:bg-primary/10 transition-colors"
                              @click="markNotificationRead(n)"
                            >
                              <span class="material-symbols-rounded text-[15px]">done</span>
                              ทำเครื่องหมายว่าอ่านแล้ว
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- User / Login -->
            <div v-if="auth.isLoggedIn" ref="userDropdownRef" class="relative flex items-center ml-1">
              <button
                type="button"
                class="flex items-center gap-2 p-1 pr-2.5 rounded-full border border-transparent hover:border-sand-dark hover:bg-sand transition-colors focus-ring"
                :aria-expanded="userDropdownOpen"
                @click.stop="userDropdownOpen = !userDropdownOpen; notificationDropdownOpen = false; wishlistDropdownOpen = false"
              >
                <div class="w-8 h-8 rounded-full bg-primary flex items-center justify-center overflow-hidden ring-2 ring-white">
                  <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" class="w-full h-full object-cover" />
                  <span v-else class="text-white text-[12px] font-bold">{{ auth.userName?.charAt(0)?.toUpperCase() }}</span>
                </div>
                <!-- ชื่อผู้ใช้กินได้ถึง 9rem — โผล่ตอน xl ขึ้นไปเท่านั้น ไม่งั้นเบียดแถบเมนูที่ lg -->
                <span class="text-[13px] font-bold text-text-dark hidden xl:block max-w-[9rem] truncate">{{ auth.shortName }}</span>
                <span class="material-symbols-rounded text-[18px] text-text-muted transition-transform duration-300" :class="{ 'rotate-180': userDropdownOpen }">expand_more</span>
              </button>

              <div v-if="userDropdownOpen" class="absolute top-full right-0 w-64 pt-3 z-[60] animation-fade-slide">
                <div class="bg-white/95 supports-[backdrop-filter]:backdrop-blur-xl rounded-2xl ring-1 ring-primary/5 border border-sand-dark/40 overflow-hidden p-1.5">
                  <div class="px-4 py-3 border-b border-sand-dark/40 mb-1">
                    <p class="text-[14px] font-bold text-text-dark truncate">{{ auth.userName }}</p>
                    <p class="text-[10px] text-accent font-bold uppercase tracking-wider">{{ isAdmin ? 'Admin' : isStaff ? 'Staff' : 'Member' }}</p>
                  </div>

                  <router-link to="/profile" class="menu-row" @click="userDropdownOpen = false">
                    <span class="material-symbols-rounded text-[20px]">account_circle</span>
                    ข้อมูลส่วนตัว
                  </router-link>

                  <p class="menu-caption">กิจกรรมของฉัน</p>
                  <router-link to="/my-bookings" class="menu-row" @click="userDropdownOpen = false">
                    <span class="material-symbols-rounded text-[20px]">confirmation_number</span>
                    การจองของฉัน
                  </router-link>
                  <router-link to="/my-reviews" class="menu-row" @click="userDropdownOpen = false">
                    <span class="material-symbols-rounded text-[20px]">reviews</span>
                    รีวิวของฉัน
                  </router-link>
                  <router-link to="/group-plans" class="menu-row" @click="userDropdownOpen = false">
                    <span class="material-symbols-rounded text-[20px]">groups</span>
                    กลุ่มไปด้วยกัน
                  </router-link>
                  <router-link to="/support" class="menu-row" @click="userDropdownOpen = false">
                    <span class="material-symbols-rounded text-[20px]">support_agent</span>
                    ศูนย์ช่วยเหลือ
                    <span v-if="supportUnread" class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-red-500 text-white text-[11px] font-bold flex items-center justify-center">
                      {{ supportUnread > 9 ? '9+' : supportUnread }}
                    </span>
                  </router-link>

                  <p class="menu-caption">สถิติของฉัน</p>
                  <router-link to="/passport" class="menu-row" @click="userDropdownOpen = false">
                    <span class="material-symbols-rounded text-[20px]">hiking</span>
                    สมุดสะสมการเดินทาง
                  </router-link>
                  <router-link to="/my-tracks" class="menu-row" @click="userDropdownOpen = false">
                    <span class="material-symbols-rounded text-[20px]">route</span>
                    บันทึกการเดินของฉัน
                  </router-link>

                  <p class="menu-caption">สิทธิพิเศษ</p>
                  <router-link to="/loyalty" class="menu-row" @click="userDropdownOpen = false">
                    <span class="material-symbols-rounded text-[20px]">stars</span>
                    แต้มสะสม
                  </router-link>
                  <router-link to="/referral" class="menu-row" @click="userDropdownOpen = false">
                    <span class="material-symbols-rounded text-[20px]">group_add</span>
                    ชวนเพื่อน
                  </router-link>

                  <template v-if="isStaff || isAdmin">
                    <p class="menu-caption">การทำงาน</p>
                    <router-link v-if="isStaff" to="/my-staff-trips" class="menu-row" @click="userDropdownOpen = false">
                      <span class="material-symbols-rounded text-[20px]">badge</span>
                      ตารางงานสตาฟ
                    </router-link>
                    <router-link v-if="isAdmin" to="/admin" class="menu-row menu-row--accent" @click="userDropdownOpen = false">
                      <span class="material-symbols-rounded text-[20px]">admin_panel_settings</span>
                      ผู้ดูแลระบบ
                    </router-link>
                  </template>

                  <div class="h-px bg-sand-dark my-1 mx-2"></div>
                  <button type="button" class="menu-row menu-row--danger w-full" @click="handleLogout">
                    <span class="material-symbols-rounded text-[20px]">logout</span>
                    ออกจากระบบ
                  </button>
                </div>
              </div>
            </div>

            <router-link
              v-else
              to="/login"
              class="flex items-center gap-1.5 px-3 py-2 rounded-full text-[14px] font-semibold text-text-mid hover:text-primary hover:bg-sand transition-colors focus-ring"
            >
              <span class="material-symbols-rounded text-[20px]">account_circle</span>
              <span>เข้าสู่ระบบ</span>
            </router-link>

            <!-- Primary CTA -->
            <router-link to="/trips" class="cta-btn ml-1">
              <span class="cta-sheen" aria-hidden="true"></span>
              <span class="material-symbols-rounded relative text-[20px] transition-transform duration-500 group-hover:rotate-[18deg]">explore</span>
              <span class="relative">จองทริป</span>
            </router-link>
          </div>

          <!-- Mobile Actions -->
          <div class="lg:hidden flex items-center gap-0.5 ml-auto">
            <template v-if="auth.isLoggedIn">
              <router-link to="/notifications" class="icon-btn w-10 h-10" aria-label="การแจ้งเตือน">
                <span class="material-symbols-rounded text-[22px]" :class="{ 'filled-icon': unreadNotifications > 0 }">notifications</span>
                <span v-if="unreadNotifications > 0" class="badge">{{ unreadNotifications }}</span>
              </router-link>

              <router-link to="/trips" class="icon-btn w-10 h-10" aria-label="รายการโปรด">
                <span
                  class="material-symbols-rounded text-[22px]"
                  :class="{ 'text-red-500': wishlistStore.favorites.length > 0 }"
                  :style="wishlistStore.favorites.length > 0 ? wishlistFilledStyle : {}"
                >favorite</span>
                <span v-if="wishlistStore.favorites.length > 0" class="badge">{{ wishlistStore.favorites.length }}</span>
              </router-link>

              <button type="button" class="icon-btn w-10 h-10" aria-label="บัญชีของฉัน" @click.stop="openAccountSheet">
                <span class="w-8 h-8 rounded-full bg-primary flex items-center justify-center overflow-hidden ring-2 ring-white">
                  <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" class="w-full h-full object-cover" />
                  <span v-else class="text-white text-[11px] font-bold">{{ auth.userName?.charAt(0)?.toUpperCase() }}</span>
                </span>
              </button>
            </template>

            <router-link v-else to="/login" class="icon-btn w-10 h-10" aria-label="เข้าสู่ระบบ">
              <span class="material-symbols-rounded text-[22px]">account_circle</span>
            </router-link>

            <button type="button" class="icon-btn w-10 h-10" aria-label="เปิดเมนู" :aria-expanded="mobileOpen" @click.stop="openNavSheet">
              <span class="material-symbols-rounded text-[24px] text-text-dark">menu</span>
            </button>
          </div>
        </div>
      </div>
    </nav>

    <!-- ── Mobile: full-screen navigation sheet ─────────────────── -->
    <Teleport to="body">
      <Transition name="sheet">
        <div v-if="mobileOpen" class="lg:hidden fixed inset-0 z-[80]" role="dialog" aria-modal="true" aria-label="เมนูหลัก">
          <div class="sheet-panel flex h-full w-full flex-col bg-white/95 supports-[backdrop-filter]:backdrop-blur-2xl">
            <div class="flex items-center justify-between px-5 pt-[calc(env(safe-area-inset-top)+1rem)] pb-4">
              <router-link to="/" class="flex items-center gap-2.5" @click="closeSheets">
                <img src="/images/logo.png?v=2" alt="ลุยเลเขา" class="w-11 h-11 object-contain" />
                <span class="flex flex-col leading-none">
                  <span class="text-[18px] font-black tracking-tight text-primary">ลุยเลเขา</span>
                  <span class="mt-1 text-[9px] font-bold uppercase tracking-[0.24em] text-text-muted">Trip &amp; Travel</span>
                </span>
              </router-link>
              <button type="button" class="icon-btn w-11 h-11" aria-label="ปิดเมนู" @click="closeSheets">
                <span class="material-symbols-rounded text-[26px] text-text-dark">close</span>
              </button>
            </div>

            <div class="flex-1 overflow-y-auto overscroll-contain px-5 pb-[calc(env(safe-area-inset-bottom)+1.5rem)]">
              <div class="sheet-item relative mb-5" :style="{ '--i': 0 }">
                <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-[20px] text-text-muted">search</span>
                <input
                  v-model="searchQuery"
                  type="text"
                  placeholder="ค้นหาทริปที่คุณต้องการ..."
                  class="w-full rounded-2xl bg-sand py-3.5 pl-12 pr-4 text-[15px] font-semibold text-text-dark placeholder:text-text-muted outline-none ring-1 ring-transparent focus:bg-white focus:ring-accent/40 transition"
                  @keyup.enter="doSearch"
                />
              </div>

              <nav class="space-y-1">
                <template v-for="(link, i) in navLinks" :key="link.label">
                  <div v-if="link.children" class="sheet-item" :style="{ '--i': i + 1 }">
                    <button
                      type="button"
                      class="sheet-row w-full"
                      :class="{ 'sheet-row--active': activeNavLabel === link.label }"
                      :aria-expanded="mobileNavDropdownOpen === link.label"
                      @click="toggleMobileNavDropdown(link.label)"
                    >
                      <span class="material-symbols-rounded text-[22px]">{{ link.icon }}</span>
                      {{ link.label }}
                      <span class="material-symbols-rounded ml-auto text-[20px] transition-transform duration-300" :class="{ 'rotate-180': mobileNavDropdownOpen === link.label }">expand_more</span>
                    </button>

                    <Transition name="mobile-submenu">
                      <div v-if="mobileNavDropdownOpen === link.label" class="mt-1 space-y-0.5 pl-4">
                        <component
                          :is="child.external ? 'a' : 'router-link'"
                          v-for="child in link.children"
                          :key="child.to"
                          v-bind="child.external ? { href: child.to } : { to: child.to }"
                          class="flex items-center gap-3.5 rounded-2xl px-5 py-2.5 text-[14px] font-semibold text-text-mid transition-colors active:scale-[0.98] hover:bg-sand hover:text-primary"
                          @click="closeSheets"
                        >
                          <span class="material-symbols-rounded text-[20px] text-text-muted">{{ child.icon }}</span>
                          {{ child.label }}
                        </component>
                      </div>
                    </Transition>
                  </div>

                  <component
                    v-else
                    :is="link.external ? 'a' : 'router-link'"
                    v-bind="link.external ? { href: link.to } : { to: link.to }"
                    class="sheet-item sheet-row"
                    :class="{ 'sheet-row--active': activeNavLabel === link.label }"
                    :style="{ '--i': i + 1 }"
                    @click="closeSheets"
                  >
                    <span class="material-symbols-rounded text-[22px]">{{ link.icon }}</span>
                    {{ link.label }}
                    <span class="material-symbols-rounded ml-auto text-[18px] text-text-muted">chevron_right</span>
                  </component>
                </template>
              </nav>

              <div class="sheet-item mt-6" :style="{ '--i': navLinks.length + 1 }">
                <router-link to="/trips" class="cta-btn w-full justify-center py-3.5 text-[16px]" @click="closeSheets">
                  <span class="cta-sheen" aria-hidden="true"></span>
                  <span class="material-symbols-rounded relative text-[20px]">explore</span>
                  <span class="relative">จองทริป</span>
                </router-link>
              </div>

              <div v-if="auth.isLoggedIn" class="sheet-item mt-3" :style="{ '--i': navLinks.length + 2 }">
                <router-link to="/support" class="flex items-center gap-3 rounded-2xl border border-sand-dark bg-sand py-3.5 px-4 text-[15px] font-bold text-text-dark transition active:scale-[0.98]" @click="closeSheets">
                  <span class="material-symbols-rounded text-[20px] text-primary">support_agent</span>
                  ศูนย์ช่วยเหลือ
                  <span v-if="supportUnread" class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-red-500 text-white text-[11px] font-bold flex items-center justify-center">
                    {{ supportUnread > 9 ? '9+' : supportUnread }}
                  </span>
                </router-link>
              </div>

              <div v-if="!auth.isLoggedIn" class="sheet-item mt-3 grid grid-cols-2 gap-2" :style="{ '--i': navLinks.length + 2 }">
                <router-link to="/login" class="flex items-center justify-center gap-2 rounded-2xl border border-sand-dark bg-sand py-3.5 text-[15px] font-bold text-primary transition active:scale-[0.98]" @click="closeSheets">
                  <span class="material-symbols-rounded text-[20px]">login</span>
                  เข้าสู่ระบบ
                </router-link>
                <router-link to="/register" class="flex items-center justify-center gap-2 rounded-2xl border border-primary/15 bg-primary/5 py-3.5 text-[15px] font-bold text-primary transition active:scale-[0.98]" @click="closeSheets">
                  <span class="material-symbols-rounded text-[20px]">person_add</span>
                  สมัครสมาชิก
                </router-link>
              </div>

              <div class="sheet-item mt-6 border-t border-sand-dark pt-5" :style="{ '--i': navLinks.length + 3 }">
                <a href="tel:0626126006" class="flex items-center gap-3 text-text-mid">
                  <span class="flex h-10 w-10 items-center justify-center rounded-full bg-sand text-primary">
                    <span class="material-symbols-rounded text-[20px]">call</span>
                  </span>
                  <span class="flex flex-col leading-tight">
                    <span class="text-[11px] font-semibold text-text-muted">สอบถามเพิ่มเติม</span>
                    <span class="text-[15px] font-bold text-text-dark">062-612-6006</span>
                  </span>
                </a>
                <p class="mt-4 flex items-center gap-1.5 text-[11px] font-semibold text-text-muted">
                  <span class="material-symbols-rounded text-[15px] text-accent filled-icon">verified_user</span>
                  ใบอนุญาตนำเที่ยวเลขที่ 12/03773
                </p>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- ── Mobile: full-screen account sheet ────────────────────── -->
    <Teleport to="body">
      <Transition name="sheet">
        <div v-if="mobileAccountOpen" class="lg:hidden fixed inset-0 z-[80]" role="dialog" aria-modal="true" aria-label="บัญชีของฉัน">
          <div class="sheet-panel flex h-full w-full flex-col bg-white/95 supports-[backdrop-filter]:backdrop-blur-2xl">
            <div class="flex items-center justify-between px-5 pt-[calc(env(safe-area-inset-top)+1rem)] pb-4">
              <div class="flex items-center gap-3">
                <div class="flex h-12 w-12 items-center justify-center overflow-hidden rounded-full bg-primary ring-2 ring-white">
                  <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" class="h-full w-full object-cover" />
                  <span v-else class="text-[15px] font-bold text-white">{{ auth.userName?.charAt(0)?.toUpperCase() }}</span>
                </div>
                <div class="min-w-0">
                  <p class="truncate text-[16px] font-bold text-text-dark">{{ auth.userName }}</p>
                  <p class="text-[10px] font-bold uppercase tracking-wider text-accent">{{ isAdmin ? 'Admin' : isStaff ? 'Staff' : 'Member' }}</p>
                </div>
              </div>
              <button type="button" class="icon-btn w-11 h-11" aria-label="ปิด" @click="closeSheets">
                <span class="material-symbols-rounded text-[26px] text-text-dark">close</span>
              </button>
            </div>

            <div class="flex-1 overflow-y-auto overscroll-contain px-5 pb-[calc(env(safe-area-inset-bottom)+1.5rem)]">
              <router-link to="/profile" class="sheet-item sheet-row" :style="{ '--i': 0 }" @click="closeSheets">
                <span class="material-symbols-rounded text-[22px]">account_circle</span>
                จัดการโปรไฟล์
              </router-link>

              <p class="sheet-item menu-caption" :style="{ '--i': 1 }">กิจกรรมของฉัน</p>
              <router-link to="/my-bookings" class="sheet-item sheet-row" :style="{ '--i': 2 }" @click="closeSheets">
                <span class="material-symbols-rounded text-[22px]">confirmation_number</span>
                การจองของฉัน
              </router-link>
              <router-link to="/my-reviews" class="sheet-item sheet-row" :style="{ '--i': 3 }" @click="closeSheets">
                <span class="material-symbols-rounded text-[22px]">reviews</span>
                รีวิวของฉัน
              </router-link>

              <p class="sheet-item menu-caption" :style="{ '--i': 4 }">สิทธิพิเศษ</p>
              <router-link to="/loyalty" class="sheet-item sheet-row" :style="{ '--i': 5 }" @click="closeSheets">
                <span class="material-symbols-rounded text-[22px]">stars</span>
                แต้มสะสม
              </router-link>
              <router-link to="/referral" class="sheet-item sheet-row" :style="{ '--i': 6 }" @click="closeSheets">
                <span class="material-symbols-rounded text-[22px]">group_add</span>
                ชวนเพื่อน
              </router-link>

              <template v-if="isStaff || isAdmin">
                <p class="sheet-item menu-caption" :style="{ '--i': 7 }">การทำงาน</p>
                <router-link v-if="isStaff" to="/my-staff-trips" class="sheet-item sheet-row" :style="{ '--i': 8 }" @click="closeSheets">
                  <span class="material-symbols-rounded text-[22px]">badge</span>
                  ตารางงานสตาฟ
                </router-link>
                <router-link v-if="isAdmin" to="/admin" class="sheet-item sheet-row sheet-row--accent" :style="{ '--i': 9 }" @click="closeSheets">
                  <span class="material-symbols-rounded text-[22px]">admin_panel_settings</span>
                  ผู้ดูแลระบบ
                </router-link>
              </template>

              <div class="sheet-item mt-5 border-t border-sand-dark pt-4" :style="{ '--i': 10 }">
                <button type="button" class="sheet-row sheet-row--danger w-full" @click="handleLogout">
                  <span class="material-symbols-rounded text-[22px]">logout</span>
                  ออกจากระบบ
                </button>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </header>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useWishlistStore } from '../stores/wishlist';
import api from '../lib/axios';

const auth = useAuthStore();
const wishlistStore = useWishlistStore();
const router = useRouter();
const route = useRoute();

const mobileOpen = ref(false);
const mobileAccountOpen = ref(false);
const searchQuery = ref('');
const unreadNotifications = ref(0);
const supportUnread = ref(0);
const isScrolled = ref(false);
const openNavDropdown = ref(null);
const mobileNavDropdownOpen = ref(null);
const userDropdownOpen = ref(false);
const wishlistDropdownOpen = ref(false);
const notificationDropdownOpen = ref(false);
const notifications = ref([]);
const notificationsLoading = ref(false);
const notificationBusy = ref(false);
const notificationError = ref('');
const navDropdownRef = ref(null);
const userDropdownRef = ref(null);
const wishlistDropdownRef = ref(null);
const notificationDropdownRef = ref(null);
const desktopSearchInput = ref(null);
const desktopSearchRef = ref(null);
const desktopSearchExpanded = ref(false);
const wishlistFilledStyle = { fontVariationSettings: "'FILL' 1" };

/*
   แถบเมนูบนเดสก์ท็อปรับได้ประมาณ 5 กลุ่ม — มากกว่านั้นจะเบียดกับโลโก้และปุ่ม
   ด้านขวาจนล้น เพิ่มหัวข้อใหม่เมื่อไหร่ให้ยุบของเดิมเข้าด้วยกัน อย่าต่อท้ายเฉย ๆ

   `mobileOnly` = โผล่เฉพาะในชีตมือถือซึ่งเป็นลิสต์แนวตั้ง มีที่เหลือเฟือ
*/
const navLinks = [
  { to: '/', icon: 'home', label: 'หน้าแรก', mobileOnly: true },
  {
    label: 'กิจกรรม',
    icon: 'explore',
    to: '/trips',
    children: [
      { to: '/trips', icon: 'explore', label: 'ทริปทั้งหมด', desc: 'ทุกทริปที่เปิดจองอยู่ตอนนี้' },
      { to: '/explore', icon: 'map', label: 'แผนที่ทริป', desc: 'ดูว่าแต่ละทริปอยู่ตรงไหนของประเทศไทย' },
      { to: '/find', icon: 'travel_explore', label: 'ค้นหาทริปที่ใช่', desc: 'ตอบไม่กี่ข้อ แล้วให้เราแนะนำ' },
      { to: '/assistant', icon: 'auto_awesome', label: 'ถามผู้ช่วย', desc: 'พิมพ์บอกงบและวันที่ไหว แล้วให้ AI หาให้' },
    ],
  },
  {
    label: 'ข้อมูลก่อนไป',
    icon: 'menu_book',
    to: '/places',
    children: [
      { to: '/places', icon: 'landscape', label: 'สถานที่ธรรมชาติ', desc: 'ความสูง ระยะเดิน และช่วงที่ควรไปของแต่ละที่' },
      { to: '/seasons', icon: 'calendar_month', label: 'เดือนไหนไปไหนดี', desc: 'ปฏิทินธรรมชาติไทยทั้ง 12 เดือน' },
      { to: '/difficulty', icon: 'trending_up', label: 'ระดับความยาก', desc: 'สายชิล ปานกลาง สายโหด ต่างกันยังไง' },
      { to: '/checklist', icon: 'checklist', label: 'เช็คลิสต์ของที่ต้องเตรียม', desc: 'ติ๊กได้ ปรินต์ได้ ใช้ฟรี' },
    ],
  },
  {
    label: 'ชุมชน',
    icon: 'diversity_3',
    to: '/feed',
    children: [
      { to: '/feed', icon: 'dynamic_feed', label: 'ฟีดจากนักเดินทาง', desc: 'รูปที่คนไปมาแล้วโพสต์เอง' },
      { to: '/gallery', icon: 'photo_library', label: 'รูปจากคนที่ไปมาแล้ว', desc: 'รูปที่ผู้ร่วมทริปถ่ายเองและแนบมากับรีวิว' },
      { to: '/reviews', icon: 'reviews', label: 'รีวิวลูกค้า', desc: 'เสียงจริงจากผู้ร่วมทริป' },
      // Blog is server-rendered (Blade) — full page load via a real <a>, not a router-link.
      { to: '/blog', icon: 'article', label: 'บทความ', desc: 'ไอเดียและคู่มือการเดินทาง', external: true },
    ],
  },
  {
    label: 'เกี่ยวกับเรา',
    icon: 'info',
    to: '/about',
    children: [
      { to: '/about', icon: 'info', label: 'เกี่ยวกับเรา', desc: 'เรื่องราวและทีมงานของเรา' },
      { to: '/goal', icon: 'flag', label: 'จุดมุ่งหมาย', desc: 'สิ่งที่เราตั้งใจทำให้สำเร็จ' },
      { to: '/how-to-book', icon: 'menu_book', label: 'วิธีการจอง', desc: 'จองทริปได้ง่ายในไม่กี่ขั้นตอน' },
      { to: '/faq', icon: 'quiz', label: 'FAQ', desc: 'คำถามที่พบบ่อย' },
      { to: '/terms', icon: 'gavel', label: 'เงื่อนไขการให้บริการ', desc: 'ข้อตกลงในการใช้บริการ' },
      { to: '/privacy', icon: 'policy', label: 'นโยบายความเป็นส่วนตัว', desc: 'เราดูแลข้อมูลของคุณอย่างไร' },
    ],
  },
  { to: '/contact', icon: 'contact_support', label: 'ติดต่อเรา' },
];

/** เมนูที่ขึ้นบนแถบเดสก์ท็อป — โลโก้ทำหน้าที่ลิงก์กลับหน้าแรกอยู่แล้ว */
const desktopNavLinks = navLinks.filter(link => !link.mobileOnly);

/* ── Gliding pill indicator ──────────────────────────────────────
   The pill tracks whichever nav item is hovered, has an open
   dropdown, or matches the current route — in that priority. */
const navListRef = ref(null);
const hoveredNavLabel = ref(null);
const indicator = ref({ left: 0, width: 0, opacity: 0 });
const navItemEls = new Map();

// Hover-to-open only makes sense on devices with a real pointer.
const canHover = typeof window !== 'undefined' && window.matchMedia('(hover: hover)').matches;
let dropdownCloseTimer = null;

function setNavItemRef(label, el) {
  const node = el?.$el ?? el;
  if (node instanceof HTMLElement) navItemEls.set(label, node);
  else navItemEls.delete(label);
}

function isMegaMenu(link) {
  return (link.children?.length ?? 0) > 3;
}

const activeNavLabel = computed(() => {
  const path = route.path;
  // ไล่จาก navLinks ทั้งชุด เพราะชีตมือถือใช้ค่านี้ไฮไลต์ "หน้าแรก" ด้วย
  // ฝั่งเดสก์ท็อปไม่พังเพราะ syncIndicator หา element ไม่เจอก็ซ่อน pill ไปเอง
  for (const link of navLinks) {
    if (link.children) {
      if (link.children.some(child => child.to === path)) return link.label;
    } else if (link.to === '/' ? path === '/' : path.startsWith(link.to)) {
      return link.label;
    }
  }
  return null;
});

const indicatorLabel = computed(() => hoveredNavLabel.value ?? openNavDropdown.value ?? activeNavLabel.value);

function syncIndicator() {
  const el = indicatorLabel.value ? navItemEls.get(indicatorLabel.value) : null;
  if (!el || !navListRef.value) {
    indicator.value = { ...indicator.value, opacity: 0 };
    return;
  }
  // Measure against the list itself: dropdown triggers sit inside a `relative`
  // wrapper, so their offsetLeft is relative to that wrapper, not the list.
  const listRect = navListRef.value.getBoundingClientRect();
  const rect = el.getBoundingClientRect();
  indicator.value = { left: rect.left - listRect.left, width: rect.width, opacity: 1 };
}

watch(indicatorLabel, () => nextTick(syncIndicator));
watch(isScrolled, () => nextTick(syncIndicator));
watch(() => route.path, () => nextTick(syncIndicator));

function onNavItemHover(label) {
  hoveredNavLabel.value = label;
  if (!canHover) return;
  clearTimeout(dropdownCloseTimer);
  const link = navLinks.find(l => l.label === label);
  openNavDropdown.value = link?.children ? label : null;
}

function onDropdownLeave() {
  if (!canHover) return;
  clearTimeout(dropdownCloseTimer);
  dropdownCloseTimer = setTimeout(() => { openNavDropdown.value = null; }, 140);
}

/* ── Sheets ─────────────────────────────────────────────────────── */
function openNavSheet() {
  mobileAccountOpen.value = false;
  mobileNavDropdownOpen.value = null;
  mobileOpen.value = true;
}

function openAccountSheet() {
  mobileOpen.value = false;
  mobileAccountOpen.value = true;
}

function closeSheets() {
  mobileOpen.value = false;
  mobileAccountOpen.value = false;
  mobileNavDropdownOpen.value = null;
}

// A full-screen sheet must not let the page behind it scroll.
watch([mobileOpen, mobileAccountOpen], ([nav, account]) => {
  document.body.style.overflow = nav || account ? 'hidden' : '';
});

function closeAllOverlays() {
  closeSheets();
  openNavDropdown.value = null;
  userDropdownOpen.value = false;
  wishlistDropdownOpen.value = false;
  notificationDropdownOpen.value = false;
  desktopSearchExpanded.value = false;
}

function handleKeydown(e) {
  if (e.key === 'Escape') closeAllOverlays();
}

function toggleDesktopSearch() {
  desktopSearchExpanded.value = !desktopSearchExpanded.value;
  if (desktopSearchExpanded.value) {
    nextTick(() => desktopSearchInput.value?.focus());
  }
}

function toggleNavDropdown(label) {
  openNavDropdown.value = openNavDropdown.value === label ? null : label;
}

function toggleMobileNavDropdown(label) {
  mobileNavDropdownOpen.value = mobileNavDropdownOpen.value === label ? null : label;
}

async function toggleNotificationDropdown() {
  notificationDropdownOpen.value = !notificationDropdownOpen.value;
  wishlistDropdownOpen.value = false;
  userDropdownOpen.value = false;

  if (notificationDropdownOpen.value) {
    await loadNotifications();
  }
}

function handleClickOutside(e) {
  const resolve = (r) => (Array.isArray(r.value) ? r.value[0] : r.value);
  const outside = (r) => {
    const el = resolve(r);
    return !el || !el.contains(e.target);
  };

  if (outside(navDropdownRef)) openNavDropdown.value = null;
  if (outside(userDropdownRef)) userDropdownOpen.value = false;
  if (outside(wishlistDropdownRef)) wishlistDropdownOpen.value = false;
  if (outside(notificationDropdownRef)) notificationDropdownOpen.value = false;
  if (outside(desktopSearchRef)) desktopSearchExpanded.value = false;
}

function doSearch() {
  if (!searchQuery.value.trim()) return;
  router.push(`/trips?search=${encodeURIComponent(searchQuery.value.trim())}`);
  closeSheets();
  searchQuery.value = '';
}

async function fetchUnreadCount() {
  if (!auth.isLoggedIn) {
    unreadNotifications.value = 0;
    supportUnread.value = 0;
    return;
  }
  try {
    const res = await api.get('/notifications/unread-count');
    unreadNotifications.value = res.data.data.count;
  } catch {}
  try {
    const res = await api.get('/support/unread-count');
    supportUnread.value = res.data.data.count;
  } catch {}
}

async function loadNotifications() {
  if (!auth.isLoggedIn || notificationsLoading.value) return;
  notificationsLoading.value = true;
  notificationError.value = '';

  try {
    const res = await api.get('/notifications', { params: { per_page: 20 } });
    notifications.value = res.data.data || [];
    await fetchUnreadCount();
  } catch {
    notificationError.value = 'โหลดการแจ้งเตือนไม่สำเร็จ';
  } finally {
    notificationsLoading.value = false;
  }
}

async function markNotificationRead(notification) {
  if (!notification || notification.is_read || notificationBusy.value) return;
  notificationBusy.value = true;

  try {
    await api.put(`/notifications/${notification.id}/read`);
    notification.is_read = true;
    notification.read_at = new Date().toISOString();
    unreadNotifications.value = Math.max(0, unreadNotifications.value - 1);
  } finally {
    notificationBusy.value = false;
  }
}

async function markAllNotificationsRead() {
  if (unreadNotifications.value === 0 || notificationBusy.value) return;
  notificationBusy.value = true;

  try {
    await api.put('/notifications/read-all');
    notifications.value.forEach(n => {
      n.is_read = true;
      n.read_at = n.read_at || new Date().toISOString();
    });
    unreadNotifications.value = 0;
  } finally {
    notificationBusy.value = false;
  }
}

async function deleteNotification(id) {
  if (notificationBusy.value) return;
  notificationBusy.value = true;

  try {
    const target = notifications.value.find(n => n.id === id);
    await api.delete(`/notifications/${id}`);
    notifications.value = notifications.value.filter(n => n.id !== id);
    if (target && !target.is_read) {
      unreadNotifications.value = Math.max(0, unreadNotifications.value - 1);
    }
  } finally {
    notificationBusy.value = false;
  }
}

async function clearNotifications() {
  if (notifications.value.length === 0 || notificationBusy.value) return;
  if (!window.confirm('ต้องการเคลียร์ข้อความแจ้งเตือนทั้งหมดใช่ไหม?')) return;

  notificationBusy.value = true;
  try {
    await api.delete('/notifications');
    notifications.value = [];
    unreadNotifications.value = 0;
  } finally {
    notificationBusy.value = false;
  }
}

// Only surface user-meaningful fields. Everything else in the payload
// (route, screen, *_id, trip_slug, type, url, ...) is internal navigation
// metadata for the app and must NOT be shown to the user.
const NOTIFICATION_DATA_LABELS = {
  booking_ref: 'รหัสจอง',
  amount: 'ยอดเงิน',
  points: 'แต้มสะสม',
};

function notificationDataEntries(data) {
  if (!data || typeof data !== 'object') return [];

  return Object.entries(data)
    .filter(([key, value]) => key in NOTIFICATION_DATA_LABELS && value != null && value !== '')
    .map(([key, value]) => {
      let display = String(value);
      if (key === 'amount') display = `฿${Number(value).toLocaleString()}`;
      else if (key === 'points') display = `${Number(value).toLocaleString()} แต้ม`;
      return { key, label: NOTIFICATION_DATA_LABELS[key], value: display };
    });
}

function notifIcon(type) {
  const map = {
    seat_alert: 'local_fire_department',
    booking_reminder: 'calendar_month',
    promo: 'featured_seasonal_and_gifts',
    system: 'info',
    loyalty: 'star',
  };
  return map[type] || 'notifications';
}

function notifStyle(type) {
  const map = {
    seat_alert: { bg: 'bg-red-50', text: 'text-red-500' },
    booking_reminder: { bg: 'bg-blue-50', text: 'text-blue-600' },
    promo: { bg: 'bg-amber-50', text: 'text-amber-600' },
    system: { bg: 'bg-sand', text: 'text-text-muted' },
    loyalty: { bg: 'bg-orange-50', text: 'text-orange-600' },
  };
  return map[type] || { bg: 'bg-primary/10', text: 'text-primary' };
}

function notificationTypeLabel(type) {
  const map = {
    seat_alert: 'ที่นั่ง',
    booking_reminder: 'เตือนการจอง',
    promo: 'โปรโมชั่น',
    system: 'ระบบ',
    loyalty: 'แต้มสะสม',
  };
  return map[type] || type || 'ทั่วไป';
}

function timeAgo(dateStr) {
  if (!dateStr) return '';

  const diff = Date.now() - new Date(dateStr).getTime();
  const minutes = Math.floor(diff / 60000);
  if (minutes < 1) return 'เมื่อกี้';
  if (minutes < 60) return `${minutes} นาทีที่แล้ว`;

  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours} ชั่วโมงที่แล้ว`;

  const days = Math.floor(hours / 24);
  if (days < 7) return `${days} วันที่แล้ว`;

  return new Date(dateStr).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
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
  window.addEventListener('resize', syncIndicator);
  document.addEventListener('click', handleClickOutside);
  document.addEventListener('touchstart', handleClickOutside);
  document.addEventListener('keydown', handleKeydown);
  nextTick(syncIndicator);
});

onUnmounted(() => {
  clearInterval(pollInterval);
  clearTimeout(dropdownCloseTimer);
  window.removeEventListener('scroll', handleScroll);
  window.removeEventListener('resize', syncIndicator);
  document.removeEventListener('click', handleClickOutside);
  document.removeEventListener('touchstart', handleClickOutside);
  document.removeEventListener('keydown', handleKeydown);
  document.body.style.overflow = '';
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
  closeAllOverlays();
  notifications.value = [];
  unreadNotifications.value = 0;
  router.push('/');
}
</script>

<style scoped>
/* ── Shared primitives ──────────────────────────────────────────── */
.focus-ring:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-accent) 35%, transparent);
}

.icon-btn {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 9999px;
  color: var(--color-text-mid);
  transition: color 0.2s ease, background-color 0.2s ease;
}
.icon-btn:hover {
  color: var(--color-primary);
  background-color: var(--color-sand);
}
.icon-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-accent) 35%, transparent);
}

.badge {
  position: absolute;
  top: 0.125rem;
  right: 0.125rem;
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 1rem;
  height: 1rem;
  padding: 0 0.2rem;
  border-radius: 9999px;
  border: 2px solid #fff;
  background-color: #ef4444;
  color: #fff;
  font-size: 9px;
  font-weight: 900;
  line-height: 1;
}

/* ── Gliding pill under the desktop nav ─────────────────────────── */
.nav-pill {
  transition:
    left 0.38s cubic-bezier(0.16, 1, 0.3, 1),
    width 0.38s cubic-bezier(0.16, 1, 0.3, 1),
    opacity 0.2s ease;
  pointer-events: none;
}

.nav-link.is-active {
  color: var(--color-primary);
  font-weight: 800;
}

.filled-icon {
  font-variation-settings: 'FILL' 1;
}

/* ── Primary CTA ────────────────────────────────────────────────── */
.cta-btn {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  overflow: hidden;
  border-radius: 9999px;
  padding: 0.625rem 1.25rem;
  font-size: 14px;
  font-weight: 700;
  color: #fff;
  background-color: var(--color-primary);
  box-shadow: 0 10px 24px -10px color-mix(in srgb, var(--color-primary) 65%, transparent);
  transition: transform 0.25s ease, box-shadow 0.25s ease;
}
.cta-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 16px 32px -12px color-mix(in srgb, var(--color-primary) 75%, transparent);
}
.cta-btn:active {
  transform: translateY(0) scale(0.98);
}
.cta-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--color-accent) 45%, transparent);
}

.cta-sheen {
  position: absolute;
  inset: 0;
  transform: translateX(-100%) skewX(-20deg);
  background-image: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.28), transparent);
  transition: transform 0.75s cubic-bezier(0.16, 1, 0.3, 1);
}
.cta-btn:hover .cta-sheen {
  transform: translateX(100%) skewX(-20deg);
}

/* ── Dropdown menu rows ─────────────────────────────────────────── */
.menu-row {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.625rem 1rem;
  border-radius: 0.75rem;
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-mid);
  transition: color 0.2s ease, background-color 0.2s ease;
}
.menu-row:hover {
  color: var(--color-primary);
  background-color: var(--color-sand);
}
.menu-row--accent {
  color: var(--color-accent);
}
.menu-row--danger {
  color: #ef4444;
}
.menu-row--danger:hover {
  color: #ef4444;
  background-color: #fef2f2;
}

.menu-caption {
  padding: 0.625rem 1rem 0.25rem;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: color-mix(in srgb, var(--color-text-muted) 75%, transparent);
}

.menu-item.router-link-active {
  background-color: var(--color-sand);
}

/* ── Mobile full-screen sheet ───────────────────────────────────── */
.sheet-row {
  display: flex;
  align-items: center;
  gap: 0.875rem;
  padding: 0.875rem 1.25rem;
  border-radius: 1rem;
  font-size: 15px;
  font-weight: 600;
  color: var(--color-text-mid);
  transition: color 0.2s ease, background-color 0.2s ease, transform 0.15s ease;
}
.sheet-row:active {
  transform: scale(0.98);
}
.sheet-row:hover {
  color: var(--color-primary);
  background-color: var(--color-sand);
}
.sheet-row--active {
  color: var(--color-primary);
  font-weight: 800;
  background-color: color-mix(in srgb, var(--color-primary) 7%, transparent);
}
.sheet-row--accent {
  color: var(--color-accent);
}
.sheet-row--danger {
  color: #ef4444;
}
.sheet-row--danger:hover {
  color: #ef4444;
  background-color: #fef2f2;
}

/* Items cascade in each time the sheet mounts. `backwards` (not `both`) so the
   animation releases `transform` on finish and .sheet-row:active can scale. */
.sheet-item {
  animation: sheetItemIn 0.45s cubic-bezier(0.16, 1, 0.3, 1) backwards;
  animation-delay: calc(var(--i, 0) * 45ms);
}

@keyframes sheetItemIn {
  from {
    opacity: 0;
    transform: translateY(14px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.sheet-enter-active,
.sheet-leave-active {
  transition: opacity 0.28s ease;
}
.sheet-enter-active .sheet-panel,
.sheet-leave-active .sheet-panel {
  transition: transform 0.38s cubic-bezier(0.16, 1, 0.3, 1);
}
.sheet-enter-from,
.sheet-leave-to {
  opacity: 0;
}
.sheet-enter-from .sheet-panel,
.sheet-leave-to .sheet-panel {
  transform: translateY(1.5rem);
}

/* Mobile nested dropdown transition */
.mobile-submenu-enter-active,
.mobile-submenu-leave-active {
  transition: all 0.2s ease;
  overflow: hidden;
}
.mobile-submenu-enter-from,
.mobile-submenu-leave-to {
  opacity: 0;
  max-height: 0;
  transform: translateY(-4px);
}
.mobile-submenu-enter-to,
.mobile-submenu-leave-from {
  opacity: 1;
  max-height: 360px;
  transform: translateY(0);
}

/* Dropdown panel entrance */
.animation-fade-slide {
  animation: fadeSlide 0.28s cubic-bezier(0.16, 1, 0.3, 1);
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
/* The mega panel is horizontally centred, so its entrance must keep that translate. */
.animation-fade-slide.-translate-x-1\/2 {
  animation-name: fadeSlideCentered;
}

@keyframes fadeSlideCentered {
  from {
    opacity: 0;
    transform: translate(-50%, -8px);
  }
  to {
    opacity: 1;
    transform: translate(-50%, 0);
  }
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
  .nav-pill,
  .cta-sheen,
  .sheet-item,
  .sheet-enter-active,
  .sheet-leave-active,
  .sheet-enter-active .sheet-panel,
  .sheet-leave-active .sheet-panel,
  .mobile-submenu-enter-active,
  .mobile-submenu-leave-active,
  .animation-fade-slide,
  .animate-trust-bar {
    transition: none;
    animation: none;
  }
}
</style>
