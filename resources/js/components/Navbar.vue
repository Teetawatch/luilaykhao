<template>
  <header ref="headerEl" class="site-nav sticky top-0 z-50 w-full">
    <!-- ── ชั้นบน (ขาว): โลโก้ · ช่องค้นหาทริป · LINE + เบอร์โทร ─────────────
         เดสก์ท็อปยุบหายเมื่อเลื่อนลง เหลือแถบเมนูเขียวสูง 64px (หน้าอื่นตั้ง sticky top-16 ไว้ตามนี้)
         มือถือ: แถวเดียว 64px เสมอ มีปุ่มบัญชี/เมนูอยู่ในนี้ -->
    <div
      class="brand-bar relative bg-white overflow-hidden h-16 border-b border-sand-dark lg:border-0"
      :class="isScrolled ? 'lg:h-0 lg:opacity-0' : 'lg:h-[4.75rem]'"
    >
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 lg:h-[4.75rem] flex items-center gap-4 xl:gap-8">
        <router-link to="/" class="flex items-center shrink-0 rounded-xl focus-ring" :aria-label="$t('ลุยเลเขา หน้าแรก')">
          <img src="/images/logo.png?v=2" :alt="$t('ลุยเลเขา')" class="w-11 h-11 lg:w-14 lg:h-14 object-contain" />
        </router-link>

        <!-- ช่องค้นหาทริป (เดสก์ท็อป) -->
        <form class="brand-search hidden lg:flex" role="search" @submit.prevent="doSearch">
          <span class="material-symbols-rounded icon-22 text-text-muted shrink-0">search</span>
          <input
            v-model="searchQuery"
            type="search"
            :aria-label="$t('ค้นหาทริป')"
            :placeholder="$t('ค้นหาทริป ชื่อดอย จังหวัด หรือกิจกรรม…')"
            class="min-w-0 flex-1 bg-transparent text-[15px] font-medium text-text-dark placeholder:text-text-muted outline-none"
          />
          <button type="submit" class="brand-search-btn">{{ $t('ค้นหา') }}</button>
        </form>

        <!-- ติดต่อ (เดสก์ท็อป) -->
        <div class="hidden lg:flex items-center gap-3 shrink-0">
          <LanguageSwitch />
          <a :href="supportLineUrl()" target="_blank" rel="noopener" class="line-pill focus-ring" :aria-label="$t('แชทกับเราทาง LINE')">
            <i class="fa-brands fa-line" aria-hidden="true"></i>
            <span class="hidden xl:inline">{{ $t('แชท LINE') }}</span>
          </a>
          <a :href="supportPhoneHref()" class="call-card focus-ring" :aria-label="$t('โทรหาเรา {supportPhone}', { supportPhone: supportPhone() })">
            <span class="call-card-ico"><span class="material-symbols-rounded icon-16 filled-icon">call</span></span>
            <span class="flex flex-col leading-none">
              <span class="text-[10.5px] font-semibold text-text-muted">{{ $t('โทรจองทริป · {supportHours}', { supportHours: supportHours() }) }}</span>
              <span class="mt-0.5 text-[15px] font-bold tracking-tight text-text-dark tabular-nums">{{ supportPhone() }}</span>
            </span>
          </a>
        </div>

        <!-- Mobile Actions -->
        <div class="lg:hidden flex items-center gap-0.5 ml-auto">
          <template v-if="auth.isLoggedIn">
            <router-link to="/notifications" class="icon-btn w-10 h-10" :aria-label="$t('การแจ้งเตือน')">
              <span class="material-symbols-rounded text-[22px]" :class="{ 'filled-icon': unreadNotifications > 0 }">notifications</span>
              <span v-if="unreadNotifications > 0" class="badge">{{ unreadNotifications }}</span>
            </router-link>

            <button type="button" class="icon-btn w-10 h-10" :aria-label="$t('รายการโปรด')" @click.stop="openWishlistSheet">
              <span
                class="material-symbols-rounded text-[22px]"
                :class="{ 'text-red-500': wishlistStore.favorites.length > 0 }"
                :style="wishlistStore.favorites.length > 0 ? wishlistFilledStyle : {}"
              >favorite</span>
              <span v-if="wishlistStore.favorites.length > 0" class="badge">{{ wishlistStore.favorites.length }}</span>
            </button>

            <button type="button" class="icon-btn w-10 h-10" :aria-label="$t('บัญชีของฉัน')" @click.stop="openAccountSheet">
              <span class="w-8 h-8 rounded-full bg-primary flex items-center justify-center overflow-hidden ring-2 ring-white">
                <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" class="w-full h-full object-cover" />
                <span v-else class="text-white text-[11px] font-bold">{{ auth.userName?.charAt(0)?.toUpperCase() }}</span>
              </span>
            </button>
          </template>

          <router-link v-else to="/login" class="icon-btn w-10 h-10" :aria-label="$t('เข้าสู่ระบบ')">
            <span class="material-symbols-rounded text-[22px]">account_circle</span>
          </router-link>

          <button type="button" class="icon-btn w-10 h-10" :aria-label="$t('เปิดเมนู')" :aria-expanded="mobileOpen" @click.stop="openNavSheet">
            <span class="material-symbols-rounded text-[24px] text-text-dark">menu</span>
          </button>
        </div>
      </div>
    </div>

    <!-- ── ชั้นเมนู (เขียวเข้มของแบรนด์) — เดสก์ท็อปเท่านั้น ──────────────── -->
    <nav :aria-label="$t('เมนูหลัก')" class="menu-bar hidden lg:block bg-[var(--color-primary)]">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center gap-2 transition-[height] duration-300" :class="isScrolled ? 'h-16' : 'h-14'">
          <!-- โลโก้บนชิปขาวโผล่เมื่อชั้นบนยุบไป (โลโก้สีเขียวเข้มมองไม่เห็นบนพื้นเขียว) -->
          <router-link v-if="isScrolled" to="/" class="mini-logo shrink-0 mr-2 rounded-xl focus-ring" :aria-label="$t('ลุยเลเขา หน้าแรก')">
            <img src="/images/logo.png?v=2" alt="" class="w-10 h-10 object-contain" />
          </router-link>

          <div ref="navDropdownRef" class="flex flex-1 items-center min-w-0">
            <div ref="navListRef" class="flex items-center -ml-3" :class="{ 'is-compact': isScrolled, 'fit-1': navFit >= 1, 'fit-2': navFit >= 2, 'fit-3': navFit >= 3 }" @mouseleave="hoveredNavLabel = null">
              <template v-for="link in navLinks" :key="link.label">
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
                    class="mb-link focus-ring"
                    :class="{ 'is-active': activeNavLabel === link.label, 'is-open': openNavDropdown === link.label }"
                    :aria-expanded="openNavDropdown === link.label"
                    aria-haspopup="true"
                    @click.stop="toggleNavDropdown(link.label)"
                  >
                    <span class="mb-ico material-symbols-rounded icon-18">{{ link.icon }}</span>
                    <span>{{ link.label }}</span>
                    <span
                      class="material-symbols-rounded icon-18 -ml-0.5 opacity-70 transition-transform duration-300"
                      :class="{ 'rotate-180': openNavDropdown === link.label }"
                    >expand_more</span>
                  </button>

                  <!-- Panel -->
                  <div
                    v-if="openNavDropdown === link.label"
                    class="absolute top-full pt-2 z-[60] animation-fade-slide"
                    :class="isMegaMenu(link)
                      ? 'left-0 w-[min(600px,calc(100vw-3rem))]'
                      : 'left-0 w-[min(340px,calc(100vw-3rem))]'"
                  >
                    <div class="bg-white rounded-xl border border-sand-dark overflow-hidden">
                      <p class="px-4 pt-3 pb-1 text-[12px] font-bold tracking-wide text-text-muted">{{ link.label }}</p>
                      <div class="grid gap-0.5 p-2 pt-1" :class="isMegaMenu(link) ? 'grid-cols-2' : 'grid-cols-1'">
                        <template v-for="child in link.children" :key="child.to">
                          <component
                            :is="child.external ? 'a' : 'router-link'"
                            v-bind="child.external ? { href: child.to } : { to: child.to }"
                            @click="openNavDropdown = null"
                            class="menu-item group/item flex items-start gap-3 rounded-lg px-3 py-2.5 transition-colors duration-200 hover:bg-sand focus-ring"
                            :class="{ 'bg-sand': !child.external && route.fullPath === child.to }"
                          >
                            <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-sand-dark bg-white text-accent transition-colors duration-200 group-hover/item:bg-accent group-hover/item:border-accent group-hover/item:text-white">
                              <span class="material-symbols-rounded icon-18">{{ child.icon }}</span>
                            </span>
                            <span class="min-w-0">
                              <span class="block text-[14.5px] font-bold text-text-dark transition-colors duration-200 group-hover/item:text-accent">{{ child.label }}</span>
                              <span v-if="child.desc" class="mt-0.5 block text-[12px] leading-snug text-text-muted">{{ child.desc }}</span>
                            </span>
                          </component>
                        </template>
                      </div>

                      <div class="flex items-center justify-between gap-3 border-t border-sand-dark bg-sand/60 px-4 py-3">
                        <p class="text-[12.5px] font-semibold text-text-muted">{{ $t('สอบถามเพิ่มเติม โทร') }} <a :href="supportPhoneHref()" class="font-bold text-text-dark hover:text-accent">{{ supportPhone() }}</a></p>
                        <router-link
                          to="/contact"
                          @click="openNavDropdown = null"
                          class="shrink-0 inline-flex items-center gap-1 rounded-full px-3 py-1.5 text-[12.5px] font-bold text-accent transition-colors hover:bg-accent-tint focus-ring"
                        >
                          {{ $t('ติดต่อเรา') }}
                          <span class="material-symbols-rounded icon-16">arrow_forward</span>
                        </router-link>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Simple link -->
                <component
                  v-else
                  :is="link.external ? 'a' : 'router-link'"
                  v-bind="link.external ? { href: link.to } : { to: link.to }"
                  :ref="el => setNavItemRef(link.label, el)"
                  class="mb-link focus-ring"
                  :class="{ 'is-active': activeNavLabel === link.label, 'xl-only': link.xlOnly }"
                  @mouseenter="onNavItemHover(link.label)"
                >
                  <span class="mb-ico material-symbols-rounded icon-18">{{ link.icon }}</span>
                  <span>{{ link.label }}</span>
                </component>
              </template>
            </div>
          </div>

          <!-- เครื่องมือทางขวา (บนพื้นเข้ม) -->
          <div class="on-dark flex items-center gap-1.5 shrink-0 ml-4 pl-4 border-l border-white/15">
            <!-- Search — โผล่เมื่อเลื่อนลงแล้วเท่านั้น (ตอนบนมีช่องค้นหาใหญ่อยู่แล้ว) -->
            <div v-if="isScrolled" ref="desktopSearchRef" class="relative flex items-center">
              <button
                type="button"
                :aria-label="$t('ค้นหาทริป')"
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
                      :placeholder="$t('ค้นหาทริป...')"
                      class="w-full bg-sand rounded-full py-2.5 pl-10 pr-9 text-[14px] font-semibold text-text-dark outline-none ring-1 ring-transparent focus:ring-accent/40 transition"
                      @keyup.enter="doSearch(); desktopSearchExpanded = false"
                    />
                    <button
                      v-if="searchQuery"
                      type="button"
                      :aria-label="$t('ล้างคำค้นหา')"
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
                :aria-label="$t('รายการโปรด')"
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
                    <span class="text-[13px] font-bold text-text-dark">{{ $t('รายการโปรด') }}</span>
                    <span class="ml-auto text-[11px] font-bold text-text-muted bg-sand px-2 py-0.5 rounded-full">{{ wishlistStore.favorites.length }}</span>
                  </div>
                  <div v-if="wishlistStore.favorites.length === 0" class="py-8 px-5 text-center">
                    <span class="material-symbols-rounded text-[40px] text-sand-dark">favorite_border</span>
                    <p class="mt-2 text-[12px] text-text-muted font-medium">{{ $t('ยังไม่มีรายการโปรด') }}</p>
                  </div>
                  <div v-else class="max-h-64 overflow-y-auto">
                    <router-link
                      v-for="trip in wishlistStore.favorites"
                      :key="favoriteKey(trip)"
                      :to="favoriteLink(trip)"
                      class="flex items-center gap-3 px-4 py-2.5 hover:bg-sand transition-colors border-b border-sand-dark/40 last:border-0"
                      @click="wishlistDropdownOpen = false"
                    >
                      <div class="w-10 h-10 rounded-lg overflow-hidden shrink-0 bg-sand-dark">
                        <img v-if="favoriteThumb(trip)" :src="favoriteThumb(trip)" class="w-full h-full object-cover" />
                      </div>
                      <div class="flex-1 min-w-0">
                        <p class="text-[12px] font-bold text-text-dark truncate">{{ favoriteTitle(trip) }}</p>
                        <p v-if="trip.price" class="text-[10px] text-primary font-bold">{{ $t('฿{Number}', { Number: Number(trip.price).toLocaleString() }) }}</p>
                      </div>
                    </router-link>
                  </div>
                  <div class="p-3 bg-sand/30">
                    <router-link to="/trips" class="block w-full py-2 text-center text-[11px] font-bold text-primary bg-primary/5 hover:bg-primary/10 rounded-xl transition-colors" @click="wishlistDropdownOpen = false">{{ $t('ดูทริปทั้งหมด') }}</router-link>
                  </div>
                </div>
              </div>
            </div>

            <!-- Notifications -->
            <div v-if="auth.isLoggedIn" ref="notificationDropdownRef" class="relative flex items-center">
              <button
                type="button"
                :aria-label="$t('การแจ้งเตือน')"
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
                        <p class="text-[14px] font-black text-text-dark leading-tight">{{ $t('การแจ้งเตือน') }}</p>
                        <p class="text-[11px] font-semibold text-text-muted mt-1">
                          {{ unreadNotifications > 0 ? $t('ยังไม่ได้อ่าน {unreadNotifications} รายการ', { unreadNotifications }) : $t('อ่านครบทุกข้อความแล้ว') }}
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
                        {{ $t('อ่านทั้งหมด') }}
                      </button>
                      <button
                        type="button"
                        :disabled="notifications.length === 0 || notificationBusy"
                        class="flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl text-[11px] font-black bg-red-50 text-red-500 hover:bg-red-100 disabled:opacity-45 disabled:cursor-not-allowed transition-colors"
                        @click="clearNotifications"
                      >
                        <span class="material-symbols-rounded text-[17px]">delete_sweep</span>
                        {{ $t('เคลียร์ข้อความ') }}
                      </button>
                    </div>
                  </div>

                  <div v-if="notificationsLoading" class="py-10 px-5 text-center">
                    <div class="w-8 h-8 mx-auto border-4 border-primary/20 border-t-primary rounded-full animate-spin"></div>
                    <p class="mt-3 text-[12px] font-bold text-text-muted">{{ $t('กำลังโหลดการแจ้งเตือน...') }}</p>
                  </div>

                  <div v-else-if="notificationError" class="py-10 px-5 text-center">
                    <span class="material-symbols-rounded text-[36px] text-red-300">error</span>
                    <p class="mt-2 text-[12px] font-bold text-text-muted">{{ notificationError }}</p>
                    <button type="button" class="mt-3 px-4 py-2 rounded-xl text-[11px] font-black text-primary bg-primary/5 hover:bg-primary/10 transition-colors" @click="loadNotifications">{{ $t('ลองใหม่') }}</button>
                  </div>

                  <div v-else-if="notifications.length === 0" class="py-10 px-5 text-center">
                    <span class="material-symbols-rounded text-[42px] text-sand-dark">notifications_off</span>
                    <p class="mt-2 text-[13px] font-black text-text-dark">{{ $t('ยังไม่มีการแจ้งเตือน') }}</p>
                    <p class="mt-1 text-[11px] font-semibold text-text-muted">{{ $t('ข้อความใหม่จะแสดงในส่วนนี้') }}</p>
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
                              :title="$t('ลบข้อความนี้')"
                              :aria-label="$t('ลบข้อความนี้')"
                              class="w-8 h-8 rounded-full flex items-center justify-center text-text-muted hover:text-red-500 hover:bg-red-50 transition-colors shrink-0"
                              @click="deleteNotification(n.id)"
                            >
                              <span class="material-symbols-rounded text-[18px]">close</span>
                            </button>
                          </div>

                          <div class="mt-3 flex flex-wrap items-center gap-1.5">
                            <span class="px-2 py-1 rounded-full text-[10px] font-black bg-sand text-text-muted">{{ notificationTypeLabel(n.type) }}</span>
                            <span class="px-2 py-1 rounded-full text-[10px] font-black" :class="n.is_read ? 'bg-sand text-text-muted' : 'bg-primary/10 text-primary'">
                              {{ n.is_read ? $t('อ่านแล้ว') : $t('ยังไม่ได้อ่าน') }}
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
                              {{ $t('ทำเครื่องหมายว่าอ่านแล้ว') }}
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
                class="flex items-center gap-2 p-1 pr-2.5 rounded-full text-white/85 hover:bg-white/10 transition-colors focus-ring"
                :aria-expanded="userDropdownOpen"
                @click.stop="userDropdownOpen = !userDropdownOpen; notificationDropdownOpen = false; wishlistDropdownOpen = false"
              >
                <div class="w-8 h-8 rounded-full bg-primary flex items-center justify-center overflow-hidden ring-2 ring-white">
                  <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" class="w-full h-full object-cover" />
                  <span v-else class="text-white text-[12px] font-bold">{{ auth.userName?.charAt(0)?.toUpperCase() }}</span>
                </div>
                <!-- ไม่แสดงชื่อบัญชีในแถบเมนู — จอกว้างเคยดันไอคอนไปชิดเมนู ชื่ออยู่ในเมนูบัญชีเมื่อกดเปิด -->
                <span class="material-symbols-rounded text-[18px] text-white/70 transition-transform duration-300" :class="{ 'rotate-180': userDropdownOpen }">expand_more</span>
              </button>

              <!-- เมนูบัญชี: การ์ดโปรไฟล์ → ทางลัด 3 ช่อง → ตาราง 2 คอลัมน์ → งานสตาฟ/แอดมิน → ออกจากระบบ
                   เคยเป็นรายการยาว 13 แถว + 4 หัวข้อ จนล้นจอโน้ตบุ๊ก -->
              <div v-if="userDropdownOpen" class="absolute top-full right-0 w-[22rem] pt-3 z-[60] animation-fade-slide">
                <div class="max-h-[calc(100vh-6rem)] overflow-y-auto overscroll-contain bg-white/95 supports-[backdrop-filter]:backdrop-blur-xl rounded-2xl ring-1 ring-primary/5 border border-sand-dark/40 p-2">
                  <router-link to="/profile" class="account-card" @click="userDropdownOpen = false">
                    <span class="w-11 h-11 rounded-full bg-primary flex items-center justify-center overflow-hidden ring-2 ring-white shrink-0">
                      <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" class="w-full h-full object-cover" />
                      <span v-else class="text-white text-[15px] font-bold">{{ auth.userName?.charAt(0)?.toUpperCase() }}</span>
                    </span>
                    <span class="flex-1 min-w-0">
                      <span class="flex items-center gap-1.5">
                        <span class="text-[14px] font-bold text-text-dark truncate">{{ auth.userName }}</span>
                        <span class="role-chip">{{ accountRoleLabel }}</span>
                      </span>
                      <span class="block mt-0.5 text-[11px] font-semibold text-text-muted">{{ $t('จัดการโปรไฟล์และข้อมูลส่วนตัว') }}</span>
                    </span>
                    <span class="material-symbols-rounded text-[20px] text-text-muted">chevron_right</span>
                  </router-link>

                  <div class="grid grid-cols-3 gap-1.5 mt-2">
                    <router-link
                      v-for="item in accountQuickLinks"
                      :key="item.to"
                      :to="item.to"
                      class="menu-tile"
                      @click="userDropdownOpen = false"
                    >
                      <span class="relative">
                        <span class="material-symbols-rounded text-[22px]">{{ item.icon }}</span>
                        <span v-if="item.badge" class="absolute -top-1.5 -right-2.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center ring-2 ring-white">
                          {{ item.badge > 9 ? '9+' : item.badge }}
                        </span>
                      </span>
                      {{ item.short }}
                    </router-link>
                  </div>

                  <div class="grid grid-cols-2 gap-0.5 mt-2 pt-2 border-t border-sand-dark/50">
                    <router-link
                      v-for="item in accountMoreLinks"
                      :key="item.to"
                      :to="item.to"
                      :title="item.label"
                      class="menu-cell"
                      @click="userDropdownOpen = false"
                    >
                      <span class="material-symbols-rounded text-[18px]">{{ item.icon }}</span>
                      <span class="truncate">{{ item.short || item.label }}</span>
                    </router-link>
                  </div>

                  <div v-if="accountWorkLinks.length" class="mt-2 pt-2 border-t border-sand-dark/50 space-y-0.5">
                    <router-link
                      v-for="item in accountWorkLinks"
                      :key="item.to"
                      :to="item.to"
                      class="menu-row"
                      :class="{ 'menu-row--accent': item.accent }"
                      @click="userDropdownOpen = false"
                    >
                      <span class="material-symbols-rounded text-[20px]">{{ item.icon }}</span>
                      {{ item.label }}
                      <span class="material-symbols-rounded ml-auto text-[18px] opacity-60">arrow_forward</span>
                    </router-link>
                  </div>

                  <div class="mt-2 pt-2 border-t border-sand-dark/50">
                    <button type="button" class="menu-row menu-row--danger w-full" @click="handleLogout">
                      <span class="material-symbols-rounded text-[20px]">logout</span>
                      {{ $t('ออกจากระบบ') }}
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <router-link
              v-else
              to="/login"
              class="flex items-center gap-1.5 px-3 py-2 rounded-full text-[14px] font-semibold text-white/85 hover:text-white hover:bg-white/10 transition-colors focus-ring"
            >
              <span class="material-symbols-rounded text-[20px]">account_circle</span>
              <span>{{ $t('เข้าสู่ระบบ') }}</span>
            </router-link>

            <router-link to="/trips" class="book-cta ml-2">
              {{ $t('จองทริป') }}
              <span class="material-symbols-rounded icon-18">arrow_forward</span>
            </router-link>
          </div>
        </div>
      </div>
    </nav>

    <!-- ── Mobile: full-screen navigation sheet ─────────────────── -->
    <Teleport to="body">
      <Transition name="sheet">
        <div v-if="mobileOpen" class="lg:hidden fixed inset-0 z-[80]" role="dialog" aria-modal="true" :aria-label="$t('เมนูหลัก')">
          <div class="sheet-panel flex h-full w-full flex-col bg-white/95 supports-[backdrop-filter]:backdrop-blur-2xl">
            <div class="flex items-center justify-between px-5 pt-[calc(env(safe-area-inset-top)+1rem)] pb-4">
              <router-link to="/" class="flex items-center gap-2.5" @click="closeSheets">
                <img src="/images/logo.png?v=2" :alt="$t('ลุยเลเขา')" class="w-11 h-11 object-contain" />
                <span class="flex flex-col leading-none">
                  <span class="text-[18px] font-black tracking-tight text-primary">{{ $t('ลุยเลเขา') }}</span>
                  <span class="mt-1 text-[9px] font-bold uppercase tracking-[0.24em] text-text-muted">Trip &amp; Travel</span>
                </span>
              </router-link>
              <button type="button" class="icon-btn w-11 h-11" :aria-label="$t('ปิดเมนู')" @click="closeSheets">
                <span class="material-symbols-rounded text-[26px] text-text-dark">close</span>
              </button>
            </div>

            <div class="flex-1 overflow-y-auto overscroll-contain px-5 pb-[calc(env(safe-area-inset-bottom)+1.5rem)]">
              <div class="sheet-item relative mb-5" :style="{ '--i': 0 }">
                <span class="material-symbols-rounded absolute left-4 top-1/2 -translate-y-1/2 text-[20px] text-text-muted">search</span>
                <input
                  v-model="searchQuery"
                  type="text"
                  :placeholder="$t('ค้นหาทริปที่คุณต้องการ...')"
                  class="w-full rounded-2xl bg-sand py-3.5 pl-12 pr-4 text-[15px] font-semibold text-text-dark placeholder:text-text-muted outline-none ring-1 ring-transparent focus:bg-white focus:ring-accent/40 transition"
                  @keyup.enter="doSearch"
                />
              </div>

              <nav class="space-y-1">
                <template v-for="(link, i) in sheetLinks" :key="link.label">
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

              <div class="sheet-item mt-4" :style="{ '--i': navLinks.length + 1 }">
                <LanguageSwitch variant="row" />
              </div>

              <div class="sheet-item mt-4" :style="{ '--i': navLinks.length + 1 }">
                <router-link to="/trips" class="cta-btn w-full justify-center py-3.5 text-[16px]" @click="closeSheets">
                  <span class="cta-sheen" aria-hidden="true"></span>
                  <span class="material-symbols-rounded relative text-[20px]">explore</span>
                  <span class="relative">{{ $t('จองทริป') }}</span>
                </router-link>
              </div>

              <div v-if="auth.isLoggedIn" class="sheet-item mt-3" :style="{ '--i': navLinks.length + 2 }">
                <router-link to="/support" class="flex items-center gap-3 rounded-2xl border border-sand-dark bg-sand py-3.5 px-4 text-[15px] font-bold text-text-dark transition active:scale-[0.98]" @click="closeSheets">
                  <span class="material-symbols-rounded text-[20px] text-primary">support_agent</span>
                  {{ $t('ศูนย์ช่วยเหลือ') }}
                  <span v-if="supportUnread" class="ml-auto min-w-5 h-5 px-1.5 rounded-full bg-red-500 text-white text-[11px] font-bold flex items-center justify-center">
                    {{ supportUnread > 9 ? '9+' : supportUnread }}
                  </span>
                </router-link>
              </div>

              <div v-if="!auth.isLoggedIn" class="sheet-item mt-3 grid grid-cols-2 gap-2" :style="{ '--i': navLinks.length + 2 }">
                <router-link to="/login" class="flex items-center justify-center gap-2 rounded-2xl border border-sand-dark bg-sand py-3.5 text-[15px] font-bold text-primary transition active:scale-[0.98]" @click="closeSheets">
                  <span class="material-symbols-rounded text-[20px]">login</span>
                  {{ $t('เข้าสู่ระบบ') }}
                </router-link>
                <router-link to="/register" class="flex items-center justify-center gap-2 rounded-2xl border border-primary/15 bg-primary/5 py-3.5 text-[15px] font-bold text-primary transition active:scale-[0.98]" @click="closeSheets">
                  <span class="material-symbols-rounded text-[20px]">person_add</span>
                  {{ $t('สมัครสมาชิก') }}
                </router-link>
              </div>

              <div class="sheet-item mt-6 border-t border-sand-dark pt-5" :style="{ '--i': navLinks.length + 3 }">
                <a :href="supportPhoneHref()" class="flex items-center gap-3 text-text-mid">
                  <span class="flex h-10 w-10 items-center justify-center rounded-full bg-sand text-primary">
                    <span class="material-symbols-rounded text-[20px]">call</span>
                  </span>
                  <span class="flex flex-col leading-tight">
                    <span class="text-[11px] font-semibold text-text-muted">{{ $t('สอบถามเพิ่มเติม') }}</span>
                    <span class="text-[15px] font-bold text-text-dark">{{ supportPhone() }}</span>
                  </span>
                </a>

                <!-- โซเชียลซ่อนอยู่บน trust bar ที่จอเล็ก — ยกมาไว้ตรงนี้แทน -->
                <div class="mt-4 flex items-center gap-2">
                  <a
                    v-for="social in socialLinks"
                    :key="social.label"
                    :href="social.href"
                    target="_blank"
                    rel="noopener"
                    class="sheet-social focus-ring"
                    :aria-label="social.label"
                  >
                    <i :class="social.icon" aria-hidden="true"></i>
                  </a>
                </div>

                <p class="mt-4 flex items-center gap-1.5 text-[11px] font-semibold text-text-muted">
                  <span class="material-symbols-rounded text-[15px] text-accent filled-icon">verified_user</span>
                  {{ $t('ใบอนุญาตนำเที่ยวเลขที่ {licence}', { licence }) }}
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
        <div v-if="mobileAccountOpen" class="lg:hidden fixed inset-0 z-[80]" role="dialog" aria-modal="true" :aria-label="$t('บัญชีของฉัน')">
          <div class="sheet-panel flex h-full w-full flex-col bg-white/95 supports-[backdrop-filter]:backdrop-blur-2xl">
            <div class="flex items-center justify-between px-5 pt-[calc(env(safe-area-inset-top)+1rem)] pb-4">
              <div class="flex items-center gap-3">
                <div class="flex h-12 w-12 items-center justify-center overflow-hidden rounded-full bg-primary ring-2 ring-white">
                  <img v-if="auth.user?.avatar_url" :src="auth.user.avatar_url" class="h-full w-full object-cover" />
                  <span v-else class="text-[15px] font-bold text-white">{{ auth.userName?.charAt(0)?.toUpperCase() }}</span>
                </div>
                <div class="min-w-0">
                  <p class="truncate text-[16px] font-bold text-text-dark">{{ auth.userName }}</p>
                  <span class="role-chip mt-1 inline-block">{{ accountRoleLabel }}</span>
                </div>
              </div>
              <button type="button" class="icon-btn w-11 h-11" :aria-label="$t('ปิด')" @click="closeSheets">
                <span class="material-symbols-rounded text-[26px] text-text-dark">close</span>
              </button>
            </div>

            <!-- ใช้รายการชุดเดียวกับ dropdown เดสก์ท็อป — มือถือเคยขาดกลุ่มไปด้วยกัน/สมุดสะสม/บันทึกการเดิน -->
            <div class="flex-1 overflow-y-auto overscroll-contain px-5 pb-[calc(env(safe-area-inset-bottom)+1.5rem)]">
              <div class="sheet-item grid grid-cols-3 gap-2" :style="{ '--i': 0 }">
                <router-link
                  v-for="item in accountQuickLinks"
                  :key="item.to"
                  :to="item.to"
                  class="menu-tile menu-tile--sheet"
                  @click="closeSheets"
                >
                  <span class="relative">
                    <span class="material-symbols-rounded text-[24px]">{{ item.icon }}</span>
                    <span v-if="item.badge" class="absolute -top-1.5 -right-2.5 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center ring-2 ring-white">
                      {{ item.badge > 9 ? '9+' : item.badge }}
                    </span>
                  </span>
                  {{ item.short }}
                </router-link>
              </div>

              <router-link to="/profile" class="sheet-item sheet-row mt-3" :style="{ '--i': 1 }" @click="closeSheets">
                <span class="material-symbols-rounded text-[22px]">account_circle</span>
                {{ $t('จัดการโปรไฟล์') }}
                <span class="material-symbols-rounded ml-auto text-[18px] text-text-muted">chevron_right</span>
              </router-link>
              <router-link
                v-for="(item, i) in accountMoreLinks"
                :key="item.to"
                :to="item.to"
                class="sheet-item sheet-row"
                :style="{ '--i': i + 2 }"
                @click="closeSheets"
              >
                <span class="material-symbols-rounded text-[22px]">{{ item.icon }}</span>
                {{ item.label }}
                <span class="material-symbols-rounded ml-auto text-[18px] text-text-muted">chevron_right</span>
              </router-link>

              <template v-if="accountWorkLinks.length">
                <p class="sheet-item menu-caption" :style="{ '--i': accountMoreLinks.length + 2 }">{{ $t('การทำงาน') }}</p>
                <router-link
                  v-for="item in accountWorkLinks"
                  :key="item.to"
                  :to="item.to"
                  class="sheet-item sheet-row"
                  :class="{ 'sheet-row--accent': item.accent }"
                  :style="{ '--i': accountMoreLinks.length + 3 }"
                  @click="closeSheets"
                >
                  <span class="material-symbols-rounded text-[22px]">{{ item.icon }}</span>
                  {{ item.label }}
                </router-link>
              </template>

              <div class="sheet-item mt-5 border-t border-sand-dark pt-4" :style="{ '--i': accountMoreLinks.length + 4 }">
                <button type="button" class="sheet-row sheet-row--danger w-full" @click="handleLogout">
                  <span class="material-symbols-rounded text-[22px]">logout</span>
                  {{ $t('ออกจากระบบ') }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- ── Mobile: full-screen wishlist sheet ───────────────────────
         เดสก์ท็อปมี dropdown อยู่แล้ว แต่มือถือเคยกดหัวใจแล้วเด้งไป /trips
         ทั้งที่ป้ายบอกว่า "รายการโปรด" — ระบบไม่มีหน้ารายการโปรดให้ไป -->
    <Teleport to="body">
      <Transition name="sheet">
        <div v-if="mobileWishlistOpen" class="lg:hidden fixed inset-0 z-[80]" role="dialog" aria-modal="true" :aria-label="$t('รายการโปรด')">
          <div class="sheet-panel flex h-full w-full flex-col bg-white/95 supports-[backdrop-filter]:backdrop-blur-2xl">
            <div class="flex items-center justify-between px-5 pt-[calc(env(safe-area-inset-top)+1rem)] pb-4">
              <div class="flex items-center gap-3">
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-red-50">
                  <span class="material-symbols-rounded text-[24px] text-red-500 filled-icon">favorite</span>
                </div>
                <div class="min-w-0">
                  <p class="text-[16px] font-bold text-text-dark">{{ $t('รายการโปรด') }}</p>
                  <p class="text-[11px] font-semibold text-text-muted">
                    {{ wishlistStore.favorites.length > 0 ? $t('บันทึกไว้ {length} ทริป', { length: wishlistStore.favorites.length }) : $t('ยังไม่ได้บันทึกทริปไหน') }}
                  </p>
                </div>
              </div>
              <button type="button" class="icon-btn w-11 h-11" :aria-label="$t('ปิด')" @click="closeSheets">
                <span class="material-symbols-rounded text-[26px] text-text-dark">close</span>
              </button>
            </div>

            <div class="flex-1 overflow-y-auto overscroll-contain px-5 pb-[calc(env(safe-area-inset-bottom)+1.5rem)]">
              <div v-if="wishlistStore.favorites.length === 0" class="sheet-item pt-10 text-center" :style="{ '--i': 0 }">
                <span class="material-symbols-rounded text-[52px] text-sand-dark">favorite_border</span>
                <p class="mt-3 text-[15px] font-bold text-text-dark">{{ $t('ยังไม่มีรายการโปรด') }}</p>
                <p class="mt-1 text-[12px] font-medium text-text-muted">{{ $t('กดรูปหัวใจบนทริปที่สนใจ แล้วกลับมาดูที่นี่ได้') }}</p>
                <router-link to="/trips" class="cta-btn mt-6 w-full justify-center py-3.5 text-[16px]" @click="closeSheets">
                  <span class="cta-sheen" aria-hidden="true"></span>
                  <span class="material-symbols-rounded relative text-[20px]">explore</span>
                  <span class="relative">{{ $t('ดูทริปทั้งหมด') }}</span>
                </router-link>
              </div>

              <template v-else>
                <div
                  v-for="(trip, i) in wishlistStore.favorites"
                  :key="favoriteKey(trip)"
                  class="sheet-item mb-2 flex items-center gap-3 rounded-2xl border border-sand-dark bg-white p-2.5"
                  :style="{ '--i': i }"
                >
                  <router-link :to="favoriteLink(trip)" class="flex min-w-0 flex-1 items-center gap-3" @click="closeSheets">
                    <div class="h-14 w-14 shrink-0 overflow-hidden rounded-xl bg-sand-dark">
                      <img v-if="favoriteThumb(trip)" :src="favoriteThumb(trip)" alt="" class="h-full w-full object-cover" />
                    </div>
                    <div class="min-w-0">
                      <p class="truncate text-[14px] font-bold text-text-dark">{{ favoriteTitle(trip) }}</p>
                      <p v-if="trip.price" class="mt-0.5 text-[12px] font-bold text-primary">{{ $t('฿{Number}', { Number: Number(trip.price).toLocaleString() }) }}</p>
                    </div>
                  </router-link>

                  <button
                    type="button"
                    class="icon-btn w-10 h-10 shrink-0"
                    :aria-label="$t('เอา {favoriteTitle} ออกจากรายการโปรด', { favoriteTitle: favoriteTitle(trip) })"
                    @click="wishlistStore.toggleFavorite(trip)"
                  >
                    <span class="material-symbols-rounded text-[21px] text-red-500" :style="wishlistFilledStyle">favorite</span>
                  </button>
                </div>

                <router-link
                  to="/trips"
                  class="sheet-item mt-4 flex items-center justify-center gap-2 rounded-2xl border border-sand-dark bg-sand py-3.5 text-[15px] font-bold text-primary transition active:scale-[0.98]"
                  :style="{ '--i': wishlistStore.favorites.length }"
                  @click="closeSheets"
                >
                  <span class="material-symbols-rounded text-[20px]">explore</span>
                  {{ $t('ดูทริปทั้งหมด') }}
                </router-link>
              </template>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </header>
</template>

<script setup>
import { $t } from '@/i18n';
import LanguageSwitch from './LanguageSwitch.vue';
import { DATE_LOCALE } from '@/i18n';
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useWishlistStore } from '../stores/wishlist';
import api from '../lib/axios';
import { licenceNo } from '../lib/licence';
import { SOCIAL_LINKS, supportHours, supportPhone, supportPhoneHref, supportLineUrl } from '../lib/contact';

// อ่านครั้งเดียวตอนสร้างคอมโพเนนต์ — ค่ามากับ shell แล้ว ไม่เปลี่ยนระหว่างหน้า
const licence = licenceNo();

const auth = useAuthStore();
const wishlistStore = useWishlistStore();
const router = useRouter();
const route = useRoute();

const mobileOpen = ref(false);
const mobileAccountOpen = ref(false);
const mobileWishlistOpen = ref(false);
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

/* รายการโปรดเก็บอ็อบเจ็กต์ทริปทั้งก้อนลง localStorage แต่ของที่บันทึกไว้ตั้งแต่
   เวอร์ชันเก่าอาจเหลือแค่ id — และหน้าทริปเปิดด้วย slug เท่านั้น (TripController
   ::show ค้นจากคอลัมน์ slug ตรง ๆ) รายการที่ไม่มี slug จึงส่งไปหน้าทริปทั้งหมด
   แทนที่จะพาไปชน 404 */
const favoriteKey = (trip) => (typeof trip === 'object' ? trip.id : trip);
const favoriteTitle = (trip) => (typeof trip === 'object' && trip.title ? trip.title : $t('ทริปที่บันทึกไว้'));
const favoriteLink = (trip) => (typeof trip === 'object' && trip.slug ? `/trips/${trip.slug}` : '/trips');
const favoriteThumb = (trip) => (typeof trip === 'object' ? trip.thumbnail_image || trip.cover_image : null);

/*
   บัญชีเดียวกับที่ Footer และหน้าติดต่อเราใช้ — แก้ที่นี่แล้วต้องแก้ที่นั่นด้วย
*/
// LINE นำหน้าเพราะเป็นช่องทางที่ลูกค้าทักจริง ที่เหลือมาจากรายการกลางที่
// Footer ใช้ร่วมกัน และตรงกับ sameAs ที่ประกาศไว้ใน structured data
const socialLinks = [
  { label: 'LINE', icon: 'fa-brands fa-line', href: supportLineUrl() },
  ...SOCIAL_LINKS,
];

/*
   เมนูหลักชั้นที่ 2 (แถบหมวดบริการ) — ความยาวคำต่างกันตามภาษา (อังกฤษยาวกว่าไทยราวครึ่ง)
   และปุ่มทางขวาก็ยาวขึ้นเมื่อเข้าสู่ระบบ จึงให้ fitNav() วัดพื้นที่จริงแล้วยุบทีละขั้น
   (ระยะห่าง → ลิงก์ทางลัด xlOnly → ไอคอน) จะเพิ่มหัวข้อให้ลองทั้งสองภาษาทั้งตอนล็อกอิน
   หมวดทริปมาจาก /categories (แอดมินจัดการเอง) — ระหว่างรอ API เมนูยังมีลิงก์ทริปทั้งหมด
*/
const tripCategories = ref([]);

const navLinks = computed(() => [
  {
    label: $t('ทริปทั้งหมด'),
    icon: 'explore',
    to: '/trips',
    children: [
      { to: '/trips', icon: 'explore', label: $t('ทริปทั้งหมด'), desc: $t('ทุกทริปที่เปิดจองอยู่ตอนนี้') },
      ...tripCategories.value.map((c) => ({
        to: `/trips?type=${c.slug}`,
        icon: c.icon || 'landscape',
        label: c.name,
        desc: c.subtitle || '',
      })),
      { to: '/calendar', icon: 'event', label: $t('ปฏิทินทริป'), desc: $t('เดือนนี้เดือนหน้ามีรอบไหนบ้าง') },
      { to: '/explore', icon: 'map', label: $t('แผนที่ทริป'), desc: $t('ดูว่าแต่ละทริปอยู่ตรงไหนของประเทศ') },
      { to: '/find', icon: 'travel_explore', label: $t('ค้นหาทริปที่ใช่'), desc: $t('ตอบไม่กี่ข้อ แล้วให้เราแนะนำ') },
      { to: '/assistant', icon: 'auto_awesome', label: $t('ถามผู้ช่วย'), desc: $t('พิมพ์บอกงบและวันที่ไหว แล้วให้ AI หาให้') },
    ],
  },
  // xlOnly: ซ่อนที่จอ 1024–1279px ให้แคปซูลเมนูพอดีแถว (ยังอยู่ในเมนูย่อย "ทริปทั้งหมด")
  { to: '/calendar', icon: 'event', label: $t('ปฏิทินทริป'), xlOnly: true },
  { to: '/explore', icon: 'map', label: $t('แผนที่ทริป'), xlOnly: true },
  { to: '/stays', icon: 'cottage', label: $t('ที่พักชุมชน') },
  {
    label: $t('ข้อมูลก่อนไป'),
    icon: 'menu_book',
    to: '/places',
    children: [
      { to: '/places', icon: 'landscape', label: $t('สถานที่ธรรมชาติ'), desc: $t('ความสูง ระยะเดิน และช่วงที่ควรไปของแต่ละที่') },
      { to: '/seasons', icon: 'calendar_month', label: $t('เดือนไหนไปไหนดี'), desc: $t('ปฏิทินธรรมชาติไทยทั้ง 12 เดือน') },
      { to: '/difficulty', icon: 'trending_up', label: $t('ระดับความยาก'), desc: $t('สายชิล ปานกลาง สายโหด ต่างกันยังไง') },
      { to: '/checklist', icon: 'checklist', label: $t('เช็คลิสต์ของที่ต้องเตรียม'), desc: $t('ติ๊กได้ ปรินต์ได้ ใช้ฟรี') },
    ],
  },
  {
    label: $t('รีวิว & ชุมชน'),
    icon: 'reviews',
    to: '/reviews',
    children: [
      { to: '/reviews', icon: 'reviews', label: $t('รีวิวลูกค้า'), desc: $t('เสียงจริงจากผู้ร่วมทริป') },
      { to: '/gallery', icon: 'photo_library', label: $t('รูปจากคนที่ไปมาแล้ว'), desc: $t('รูปที่ผู้ร่วมทริปถ่ายเองและแนบมากับรีวิว') },
      { to: '/feed', icon: 'dynamic_feed', label: $t('ฟีดจากนักเดินทาง'), desc: $t('รูปที่คนไปมาแล้วโพสต์เอง') },
      // Blog is server-rendered (Blade) — full page load via a real <a>, not a router-link.
      { to: '/blog', icon: 'article', label: $t('บทความ'), desc: $t('ไอเดียและคู่มือการเดินทาง'), external: true },
    ],
  },
  {
    label: $t('เกี่ยวกับเรา'),
    icon: 'info',
    to: '/about',
    children: [
      { to: '/about', icon: 'info', label: $t('เกี่ยวกับเรา'), desc: $t('เรื่องราวและทีมงานของเรา') },
      { to: '/goal', icon: 'flag', label: $t('จุดมุ่งหมาย'), desc: $t('สิ่งที่เราตั้งใจทำให้สำเร็จ') },
      { to: '/how-to-book', icon: 'menu_book', label: $t('วิธีการจอง'), desc: $t('จองทริปได้ง่ายในไม่กี่ขั้นตอน') },
      { to: '/faq', icon: 'quiz', label: $t('คำถามที่พบบ่อย'), desc: $t('รวมคำตอบเรื่องการจอง การจ่าย และการเดินทาง') },
      { to: '/contact', icon: 'contact_support', label: $t('ติดต่อเรา'), desc: $t('โทร LINE อีเมล และเวลาทำการ') },
      { to: '/problem', icon: 'report', label: $t('แจ้งปัญหาการใช้งาน'), desc: $t('จองไม่ได้ จ่ายไม่ผ่าน บอกเราได้เลย') },
      { to: '/terms', icon: 'gavel', label: $t('เงื่อนไขการให้บริการ'), desc: $t('ข้อตกลงในการใช้บริการ') },
      { to: '/privacy', icon: 'policy', label: $t('นโยบายความเป็นส่วนตัว'), desc: $t('เราดูแลข้อมูลของคุณอย่างไร') },
    ],
  },
]);

// แผงเมนูมือถือมี "หน้าแรก" นำ เพราะมือถือไม่มีแถวลิงก์บริการลูกค้า
const sheetLinks = computed(() => [{ to: '/', icon: 'home', label: $t('หน้าแรก') }, ...navLinks.value]);

async function fetchTripCategories() {
  try {
    const res = await api.get('/categories');
    tripCategories.value = (res.data.data || []).slice(0, 8);
  } catch {
    // เมนูยังใช้ได้ด้วยลิงก์ทริปทั้งหมด
  }
}

/* ความสูงจริงของหัวเว็บ → --site-header-h ให้ของที่ติดใต้หัว (แถบการจองค้าง) วางตัวตาม
   หัวเว็บสูงไม่เท่ากันระหว่างก่อน/หลังเลื่อน และระหว่างมือถือ/เดสก์ท็อป */
const headerEl = ref(null);
let headerObserver = null;

function publishHeaderHeight() {
  const h = headerEl.value?.offsetHeight;
  if (h) document.documentElement.style.setProperty('--site-header-h', `${h}px`);
}

/* ── Desktop service bar ───────────────────────────────────────── */
const navListRef = ref(null);

/*
   แถบเมนูกว้างได้ไม่เกิน max-w-7xl ไม่ว่าจอจะกว้างแค่ไหน เงื่อนไขตามความกว้างจอจึงเดาไม่ถูก
   (หน้าอังกฤษที่จอ ≥1440px เคยเปิดไอคอนกลับมาจน "About us" ล้นไปทับเส้นคั่น)
   — วัดจากพื้นที่ที่เหลือจริงแทน แล้วยุบทีละขั้นจนพอดี:
   1 บีบระยะห่าง · 2 ซ่อนลิงก์ทางลัด (ยังอยู่ในเมนูย่อย "ทริปทั้งหมด") · 3 ซ่อนไอคอน
   เจ้าของอยากเห็นไอคอนหน้าเมนู — ไอคอนจึงเป็นสิ่งสุดท้ายที่ยอมตัด
*/
const NAV_FIT_MAX = 3;
const navFit = ref(0);
let navFitRun = 0;
let navFitObserver = null;

async function fitNav() {
  const box = navDropdownRef.value;
  const list = navListRef.value;
  // แถบนี้ซ่อนบนมือถือ (display: none) — ไม่มีอะไรให้วัด
  if (!box || !list || box.offsetParent === null) return;
  const run = ++navFitRun;
  navFit.value = 0;
  await nextTick();
  // แถวเมนูขยับซ้าย 12px ด้วย -ml-3 จึงมีที่มากกว่ากล่องเท่านั้น
  while (run === navFitRun && navFit.value < NAV_FIT_MAX && list.scrollWidth > box.clientWidth + 12) {
    navFit.value++;
    await nextTick();
  }
}
const hoveredNavLabel = ref(null);
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

/* หน้าย่อยต้องไฮไลต์หัวข้อแม่ด้วย — /trips/doi-inthanon คือ "กิจกรรม" และ
   /places/khao-luang คือ "ข้อมูลก่อนไป" ซึ่งเป็นหน้าที่คนอยู่นานที่สุดพอดี
   เทียบขอบด้วย `/` ปิดท้าย ไม่ใช่ startsWith เปล่า ๆ ไม่งั้น /contact-us
   จะไปปลุกหัวข้อ /contact */
function matchesPath(to, path) {
  to = to.split('?')[0];
  if (to === '/') return path === '/';
  return path === to || path.startsWith(`${to}/`);
}

const activeNavLabel = computed(() => {
  const path = route.path;
  // ลิงก์เดี่ยวก่อน — /calendar อยู่ทั้งในเมนูย่อย "ทริปทั้งหมด" และเป็นปุ่มของตัวเอง
  const direct = navLinks.value.find((l) => !l.children && matchesPath(l.to, path));
  if (direct) return direct.label;
  for (const link of navLinks.value) {
    if (link.children) {
      if (link.children.some(child => matchesPath(child.to, path))) return link.label;
    } else if (matchesPath(link.to, path)) {
      return link.label;
    }
  }
  return null;
});

function onNavItemHover(label) {
  hoveredNavLabel.value = label;
  if (!canHover) return;
  clearTimeout(dropdownCloseTimer);
  const link = navLinks.value.find(l => l.label === label);
  openNavDropdown.value = link?.children ? label : null;
}

function onDropdownLeave() {
  if (!canHover) return;
  clearTimeout(dropdownCloseTimer);
  dropdownCloseTimer = setTimeout(() => { openNavDropdown.value = null; }, 140);
}

/* ── Sheets ─────────────────────────────────────────────────────── */
// เปิดได้ทีละใบเท่านั้น — ทุกตัวเปิดผ่าน openSheet ตัวเดียว จะได้ไม่มีทางลืมปิดใบอื่น
function openSheet(which) {
  mobileNavDropdownOpen.value = null;
  mobileOpen.value = which === 'nav';
  mobileAccountOpen.value = which === 'account';
  mobileWishlistOpen.value = which === 'wishlist';
}

const openNavSheet = () => openSheet('nav');
const openAccountSheet = () => openSheet('account');
const openWishlistSheet = () => openSheet('wishlist');

function closeSheets() {
  openSheet(null);
}

// A full-screen sheet must not let the page behind it scroll.
watch([mobileOpen, mobileAccountOpen, mobileWishlistOpen], (sheets) => {
  document.body.style.overflow = sheets.some(Boolean) ? 'hidden' : '';
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
    notificationError.value = $t('โหลดการแจ้งเตือนไม่สำเร็จ');
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
  if (!window.confirm($t('ต้องการเคลียร์ข้อความแจ้งเตือนทั้งหมดใช่ไหม?'))) return;

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
  booking_ref: $t('รหัสจอง'),
  amount: $t('ยอดเงิน'),
  points: $t('แต้มสะสม'),
};

function notificationDataEntries(data) {
  if (!data || typeof data !== 'object') return [];

  return Object.entries(data)
    .filter(([key, value]) => key in NOTIFICATION_DATA_LABELS && value != null && value !== '')
    .map(([key, value]) => {
      let display = String(value);
      if (key === 'amount') display = $t('฿{Number}', { Number: Number(value).toLocaleString() });
      else if (key === 'points') display = $t('{Number} แต้ม', { Number: Number(value).toLocaleString() });
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
    seat_alert: $t('ที่นั่ง'),
    booking_reminder: $t('เตือนการจอง'),
    promo: $t('โปรโมชั่น'),
    system: $t('ระบบ'),
    loyalty: $t('แต้มสะสม'),
  };
  return map[type] || type || $t('ทั่วไป');
}

function timeAgo(dateStr) {
  if (!dateStr) return '';

  const diff = Date.now() - new Date(dateStr).getTime();
  const minutes = Math.floor(diff / 60000);
  if (minutes < 1) return $t('เมื่อกี้');
  if (minutes < 60) return $t('{minutes} นาทีที่แล้ว', { minutes });

  const hours = Math.floor(minutes / 60);
  if (hours < 24) return $t('{hours} ชั่วโมงที่แล้ว', { hours });

  const days = Math.floor(hours / 24);
  if (days < 7) return $t('{days} วันที่แล้ว', { days });

  return new Date(dateStr).toLocaleDateString(DATE_LOCALE, { day: 'numeric', month: 'short', year: 'numeric' });
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
  document.addEventListener('keydown', handleKeydown);
  fetchTripCategories();
  publishHeaderHeight();
  if (typeof ResizeObserver !== 'undefined' && headerEl.value) {
    headerObserver = new ResizeObserver(publishHeaderHeight);
    headerObserver.observe(headerEl.value);
  }
  fitNav();
  // กล่องเมนูกว้าง = ที่เหลือจากปุ่มทางขวา (flex-1 + min-w-0) ไม่ขึ้นกับเนื้อหาในแถว
  // จึงไม่วนลูปกับการยุบเมนู — เปลี่ยนเมื่อจอเปลี่ยน ล็อกอิน/ออก หรือเลื่อนจนโลโก้เล็กโผล่
  if (typeof ResizeObserver !== 'undefined' && navDropdownRef.value) {
    navFitObserver = new ResizeObserver(() => fitNav());
    navFitObserver.observe(navDropdownRef.value);
  }
  // ฟอนต์เว็บโหลดเสร็จทีหลัง ความกว้างคำเปลี่ยน
  document.fonts?.ready?.then(() => fitNav());
});

onUnmounted(() => {
  clearInterval(pollInterval);
  clearTimeout(dropdownCloseTimer);
  headerObserver?.disconnect();
  navFitObserver?.disconnect();
  window.removeEventListener('scroll', handleScroll);
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

// เมนูบัญชีชุดเดียว ใช้ทั้ง dropdown เดสก์ท็อปและ sheet มือถือ
// `short` คือป้ายสั้นสำหรับช่องแคบ (ทางลัด 3 ช่อง / ตาราง 2 คอลัมน์)
const accountRoleLabel = computed(() => (isAdmin.value ? 'Admin' : isStaff.value ? 'Staff' : 'Member'));

const accountQuickLinks = computed(() => [
  { to: '/my-bookings', icon: 'confirmation_number', label: $t('การจองของฉัน'), short: $t('การจอง') },
  { to: '/loyalty', icon: 'stars', label: $t('แต้มสะสม'), short: $t('แต้มสะสม') },
  { to: '/support', icon: 'support_agent', label: $t('ศูนย์ช่วยเหลือ'), short: $t('ช่วยเหลือ'), badge: supportUnread.value },
]);

const accountMoreLinks = computed(() => [
  { to: '/my-stays', icon: 'cottage', label: $t('ที่พักของฉัน') },
  { to: '/my-reviews', icon: 'reviews', label: $t('รีวิวของฉัน') },
  { to: '/group-plans', icon: 'groups', label: $t('กลุ่มไปด้วยกัน') },
  { to: '/passport', icon: 'hiking', label: $t('สมุดสะสมการเดินทาง'), short: $t('สมุดสะสมทริป') },
  { to: '/my-tracks', icon: 'route', label: $t('บันทึกการเดินของฉัน'), short: $t('บันทึกการเดิน') },
  { to: '/referral', icon: 'group_add', label: $t('ชวนเพื่อน') },
  ...(auth.user?.is_partner ? [{ to: '/partner', icon: 'handshake', label: $t('พาร์ทเนอร์ช่วยขาย'), short: $t('พาร์ทเนอร์') }] : []),
]);

const accountWorkLinks = computed(() => [
  ...(isStaff.value ? [{ to: '/my-staff-trips', icon: 'badge', label: $t('ตารางงานสตาฟ') }] : []),
  ...(isAdmin.value ? [{ to: '/admin', icon: 'admin_panel_settings', label: $t('ผู้ดูแลระบบ'), accent: true }] : []),
]);

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

/* ── ชั้นบน (ขาว) ─────────────────────────────────────────────────── */
.brand-bar {
  transition: height 0.3s ease, opacity 0.2s ease;
}
.brand-search {
  flex: 1;
  max-width: 34rem;
  margin: 0 auto;
  align-items: center;
  gap: 0.6rem;
  height: 3rem;
  padding: 0 0.375rem 0 1.1rem;
  border-radius: 9999px;
  border: 1.5px solid var(--color-sand-dark);
  background-color: var(--color-sand);
  transition: border-color 0.2s ease, background-color 0.2s ease;
}
.brand-search:focus-within {
  border-color: var(--color-accent);
  background-color: #fff;
}
.brand-search input::-webkit-search-cancel-button {
  display: none;
}
.brand-search-btn {
  flex-shrink: 0;
  height: 2.25rem;
  padding: 0 1.25rem;
  border-radius: 9999px;
  background-color: var(--color-accent);
  color: #fff;
  font-size: 14.5px;
  font-weight: 700;
  transition: background-color 0.2s ease;
}
.brand-search-btn:hover {
  background-color: var(--color-accent-mid);
}

.line-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  height: 2.25rem;
  min-width: 2.25rem;
  padding: 0 0.6rem;
  border-radius: 9999px;
  border: 1.5px solid #06c755;
  color: #05a649;
  font-size: 13px;
  font-weight: 700;
  white-space: nowrap;
  transition: background-color 0.2s ease, color 0.2s ease;
}
.line-pill i {
  font-size: 17px;
  line-height: 1;
}
@media (min-width: 1280px) {
  .line-pill { padding: 0 0.8rem 0 0.7rem; }
}
.line-pill:hover {
  background-color: #06c755;
  color: #fff;
}

/* เบอร์โทรเป็นการ์ดเล็ก — ไอคอนในกรอบมนสีเขียวอ่อน + เวลาทำการ ไม่ใช่ตัวเลขยักษ์ */
.call-card {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.3rem 0.8rem 0.3rem 0.3rem;
  border-radius: 0.85rem;
  border: 1px solid var(--color-sand-dark);
  white-space: nowrap;
  transition: border-color 0.2s ease, background-color 0.2s ease;
}
.call-card:hover {
  border-color: color-mix(in srgb, var(--color-accent) 40%, transparent);
  background-color: var(--color-accent-tint);
}
.call-card-ico {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 1.875rem;
  height: 1.875rem;
  border-radius: 0.6rem;
  background-color: var(--color-accent);
  color: #fff;
}

/* ── ชั้นเมนู (เขียวเข้ม) ───────────────────────────────────────────── */
.mb-link {
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.4rem;
  height: 2.5rem;
  padding: 0 0.7rem;
  border-radius: 0.6rem;
  font-size: 14px;
  font-weight: 500;
  white-space: nowrap;
  color: rgba(255, 255, 255, 0.82);
  transition: color 0.2s ease, background-color 0.2s ease;
}
.mb-link:hover,
.mb-link.is-open {
  color: #fff;
  background-color: rgba(255, 255, 255, 0.08);
}
.mb-link.is-active {
  color: #fff;
  font-weight: 600;
}
/* ขีดเขียวสดใต้เมนูที่อยู่ ชิดขอบล่างของแถบ */
.mb-link.is-active::after {
  content: '';
  position: absolute;
  left: 0.7rem;
  right: 0.7rem;
  bottom: -0.5rem;
  height: 3px;
  border-radius: 3px 3px 0 0;
  background-color: var(--color-accent-light);
}
.mb-ico {
  display: none;
  color: var(--color-accent-light);
}
@media (min-width: 1280px) {
  .mb-ico { display: inline-block; }
  /* ตอนเลื่อนลงมีโลโก้กับปุ่มค้นหาเพิ่มเข้ามาในแถบ — ซ่อนไอคอนเมนูให้พอดีแถว */
  .is-compact .mb-ico { display: none; }
}

/* ซ่อนที่ 1024–1279px — ใช้คลาสของเราเอง เพราะ display ของ .mb-link ใน scoped style
   ชนะ utility "hidden" ของ Tailwind */
.mb-link.xl-only {
  display: none;
}
@media (min-width: 1280px) {
  .mb-link.xl-only { display: flex; }
}

/* ยุบตามพื้นที่จริงที่ fitNav() วัดได้ (ทับเงื่อนไขตามความกว้างจอข้างบน) */
.fit-1 .mb-link { gap: 0.3rem; padding: 0 0.5rem; }
.fit-1 .mb-link.is-active::after { left: 0.5rem; right: 0.5rem; }
.fit-2 .mb-link.xl-only { display: none; }
.fit-3 .mb-ico { display: none; }

.mini-logo {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0.15rem;
  border-radius: 0.75rem;
  background-color: #fff;
  animation: miniLogoIn 0.3s ease both;
}
@keyframes miniLogoIn {
  from { opacity: 0; transform: scale(0.85); }
  to { opacity: 1; transform: scale(1); }
}

/* ปุ่มขาวบนพื้นเขียว — เด่นที่สุดในแถบโดยไม่ต้องเพิ่มสีใหม่ */
.book-cta {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  height: 2.5rem;
  padding: 0 1.1rem 0 1.3rem;
  border-radius: 9999px;
  background-color: #fff;
  color: var(--color-primary);
  font-size: 14.5px;
  font-weight: 800;
  white-space: nowrap;
  transition: background-color 0.2s ease, color 0.2s ease;
}
.book-cta:hover {
  background-color: var(--color-accent-light);
  color: var(--color-primary);
}
.book-cta .material-symbols-rounded {
  transition: transform 0.2s ease;
}
.book-cta:hover .material-symbols-rounded {
  transform: translateX(2px);
}

/* ปุ่มไอคอน (ค้นหา/รายการโปรด/แจ้งเตือน) บนพื้นเข้ม */
.on-dark .icon-btn {
  color: rgba(255, 255, 255, 0.85);
}
.on-dark .icon-btn:hover {
  color: #fff;
  background-color: rgba(255, 255, 255, 0.1);
}
.on-dark .badge {
  border-color: var(--color-primary);
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
  background-color: var(--color-accent);
  box-shadow: 0 10px 24px -10px color-mix(in srgb, var(--color-accent) 65%, transparent);
  transition: transform 0.25s ease, box-shadow 0.25s ease;
}
.cta-btn:hover {
  background-color: var(--color-accent-mid);
  transform: translateY(-1px);
  box-shadow: 0 16px 32px -12px color-mix(in srgb, var(--color-accent) 75%, transparent);
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

/* ── Account menu ───────────────────────────────────────────────── */
.account-card {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem;
  border-radius: 0.875rem;
  background-color: var(--color-sand);
  transition: background-color 0.2s ease;
}
.account-card:hover {
  background-color: color-mix(in srgb, var(--color-primary) 8%, var(--color-sand));
}
.role-chip {
  flex-shrink: 0;
  padding: 0.125rem 0.5rem;
  border-radius: 9999px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--color-accent);
  background-color: color-mix(in srgb, var(--color-accent) 12%, transparent);
}
.menu-tile {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.375rem;
  padding: 0.75rem 0.25rem;
  border-radius: 0.875rem;
  border: 1px solid color-mix(in srgb, var(--color-sand-dark) 60%, transparent);
  font-size: 12px;
  font-weight: 700;
  color: var(--color-text-dark);
  transition: color 0.2s ease, background-color 0.2s ease, border-color 0.2s ease;
}
.menu-tile .material-symbols-rounded {
  color: var(--color-primary);
}
.menu-tile:hover,
.menu-tile.router-link-active {
  border-color: color-mix(in srgb, var(--color-primary) 25%, transparent);
  background-color: color-mix(in srgb, var(--color-primary) 6%, transparent);
  color: var(--color-primary);
}
.menu-tile--sheet {
  padding: 1rem 0.25rem;
  font-size: 13px;
}
.menu-cell {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
  padding: 0.5rem 0.625rem;
  border-radius: 0.625rem;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--color-text-mid);
  transition: color 0.2s ease, background-color 0.2s ease;
}
.menu-cell .material-symbols-rounded {
  flex-shrink: 0;
  color: var(--color-text-muted);
  transition: color 0.2s ease;
}
.menu-cell:hover,
.menu-cell.router-link-active {
  color: var(--color-primary);
  background-color: var(--color-sand);
}
.menu-cell:hover .material-symbols-rounded,
.menu-cell.router-link-active .material-symbols-rounded {
  color: var(--color-primary);
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

.sheet-social {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.5rem;
  height: 2.5rem;
  border-radius: 9999px;
  background-color: var(--color-sand);
  color: var(--color-text-mid);
  transition: background-color 0.2s ease, color 0.2s ease, transform 0.15s ease;
}
.sheet-social i {
  font-size: 15px;
  line-height: 1;
}
.sheet-social:hover {
  background-color: var(--color-accent);
  color: #fff;
}
.sheet-social:active {
  transform: scale(0.94);
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
  .brand-bar,
  .mini-logo,
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
