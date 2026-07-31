<template>
  <div class="admin-layout">
    <!-- Sidebar -->
    <aside class="admin-sidebar" :class="{ collapsed: sidebarCollapsed }">
      <div class="sidebar-header flex flex-col items-center">
        <img src="/images/logo.png?v=2" alt="TrailDive" class="w-18 h-18 object-contain" />
        <span v-if="!sidebarCollapsed" class="brand-subtitle mt-1">แผงควบคุมระบบ</span>
      </div>

      <nav class="sidebar-nav">
        <div v-for="group in menuGroups" :key="group.id" class="nav-group">
          <div 
            class="group-header" 
            :class="{ active: group.isOpen }"
            @click="toggleGroup(group.id)"
          >
            <div class="group-title-wrapper">
              <i :class="group.icon"></i>
              <span v-if="!sidebarCollapsed">{{ group.label }}</span>
            </div>
            <i 
              v-if="!sidebarCollapsed" 
              class="fas fa-chevron-right chevron" 
              :class="{ rotated: group.isOpen }"
            ></i>
          </div>
          
          <div v-show="group.isOpen && !sidebarCollapsed" class="group-items">
            <router-link
              v-for="item in group.items"
              :key="item.to"
              :to="item.to"
              class="nav-item sub-item"
              :class="{ active: item.to === '/admin' ? $route.path === '/admin' : $route.path.startsWith(item.to) }"
            >
              <i :class="item.icon"></i>
              <span>{{ item.label }}</span>
              <span v-if="item.badge === 'sos' && sosCount" class="nav-badge">{{ sosCount }}</span>
            </router-link>
          </div>

          <!-- Mini menu for collapsed sidebar -->
          <div v-if="sidebarCollapsed" class="collapsed-group-items">
             <router-link
              v-for="item in group.items"
              :key="item.to"
              :to="item.to"
              class="nav-item collapsed-sub-item"
              :title="item.label"
              :class="{ active: item.to === '/admin' ? $route.path === '/admin' : $route.path.startsWith(item.to) }"
            >
              <i :class="item.icon"></i>
              <span v-if="item.badge === 'sos' && sosCount" class="nav-badge collapsed-badge">{{ sosCount }}</span>
            </router-link>
          </div>
        </div>
      </nav>

      <div class="sidebar-footer">
        <router-link to="/" class="nav-item back-link">
          <i class="fas fa-arrow-left"></i>
          <span v-if="!sidebarCollapsed">กลับหน้าเว็บไซต์</span>
        </router-link>
      </div>
    </aside>

    <!-- Main Content -->
    <div class="admin-main" :class="{ expanded: sidebarCollapsed }">
      <!-- Top Bar -->
      <header class="admin-topbar">
        <button class="toggle-btn" @click="sidebarCollapsed = !sidebarCollapsed">
          <i class="fas fa-bars"></i>
        </button>

        <div class="topbar-right">
          <div class="admin-user">
            <div class="user-avatar">
              {{ auth.userName?.charAt(0)?.toUpperCase() || 'A' }}
            </div>
            <div class="user-info" v-if="!sidebarCollapsed || true">
              <span class="user-name">{{ auth.userName }}</span>
              <span class="user-role">Admin</span>
            </div>
          </div>
          <button class="logout-btn" @click="handleLogout" title="ออกจากระบบ">
            <i class="fas fa-sign-out-alt"></i>
          </button>
        </div>
      </header>

      <!-- Page Content -->
      <main class="admin-content">
        <router-view />
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import api from '../lib/axios';

const router = useRouter();
const auth = useAuthStore();
const sidebarCollapsed = ref(false);

// จำนวนเคส SOS ที่ยังไม่ปิด — ติดไว้บนเมนูเพื่อให้เห็นได้จากทุกหน้าในหลังบ้าน
// ไม่ใช่เฉพาะตอนเปิดหน้า SOS ค้างไว้
const sosCount = ref(0);
let sosTimer = null;

const loadSosCount = async () => {
  try {
    const res = await api.get('/admin/sos/active-count');
    sosCount.value = res.data.data.count || 0;
  } catch {
    // เน็ตสะดุด/สิทธิ์ไม่พอ — ไม่ต้องรบกวนผู้ใช้ รอบหน้าค่อยลองใหม่
  }
};

const menuGroups = ref([
  {
    id: 'main',
    label: 'หน้าหลัก',
    icon: 'fas fa-home',
    items: [
      { to: '/admin', icon: 'fas fa-tachometer-alt', label: 'แดชบอร์ด' },
      { to: '/admin/action-queue', icon: 'fas fa-list-check', label: 'สิ่งที่รอคุณ' },
      { to: '/admin/analytics', icon: 'fas fa-chart-area', label: 'Analytics' },
      { to: '/admin/reports', icon: 'fas fa-chart-line', label: 'รายงาน' },
      { to: '/admin/finance', icon: 'fas fa-money-bill-wave', label: 'สรุปกำไร/ค่าใช้จ่าย' },
    ],
    isOpen: true
  },
  {
    id: 'trips',
    label: 'จัดการทริป',
    icon: 'fas fa-map-marked-alt',
    items: [
      { to: '/admin/schedule-overview', icon: 'fas fa-th-list', label: 'ตารางที่นั่งว่าง' },
      { to: '/admin/trips', icon: 'fas fa-route', label: 'ทริปทั้งหมด' },
      { to: '/admin/schedules', icon: 'fas fa-calendar-alt', label: 'รอบเดินทาง' },
      { to: '/admin/at-risk', icon: 'fas fa-triangle-exclamation', label: 'รอบเสี่ยงไม่ออก' },
      { to: '/admin/flexi-price', icon: 'fas fa-people-arrows', label: 'Flexi-Price ไปต่อ' },
      { to: '/admin/calendar', icon: 'fas fa-calendar', label: 'ปฏิทินทริป' },
      { to: '/admin/categories', icon: 'fas fa-tags', label: 'หมวดหมู่กิจกรรม' },
    ],
    isOpen: false
  },
  {
    id: 'bookings',
    label: 'การจองและลูกค้า',
    icon: 'fas fa-ticket-alt',
    items: [
      { to: '/admin/manual-booking', icon: 'fas fa-headset', label: 'จองแทนลูกค้า' },
      { to: '/admin/bookings', icon: 'fas fa-ticket-alt', label: 'การจอง' },
      { to: '/admin/customers', icon: 'fas fa-user-friends', label: 'จัดการลูกค้า' },
      { to: '/admin/birthdate-followup', icon: 'fas fa-birthday-cake', label: 'ตามเก็บวันเกิด' },
      { to: '/admin/reviews', icon: 'fas fa-star', label: 'รีวิวจากลูกค้า' },
      { to: '/admin/trip-posts', icon: 'fas fa-images', label: 'ฟีดนักเดินทาง' },
      { to: '/admin/inquiries', icon: 'fas fa-envelope', label: 'ข้อความติดต่อ' },
    ],
    isOpen: false
  },
  {
    id: 'transport',
    label: 'ขนส่งและรถตู้',
    icon: 'fas fa-shuttle-van',
    items: [
      { to: '/admin/van-trips', icon: 'fas fa-shuttle-van', label: 'บริการรถตู้' },
      { to: '/admin/vehicles', icon: 'fas fa-car', label: 'จัดการยานพาหนะ' },
      { to: '/admin/drivers', icon: 'fas fa-id-card', label: 'ทะเบียนคนขับ' },
      { to: '/admin/tracking', icon: 'fas fa-map-marker-alt', label: 'ติดตามรถ GPS' },
      { to: '/admin/maintenance', icon: 'fas fa-tools', label: 'ประวัติบำรุงรักษา' },
    ],
    isOpen: false
  },
  {
    id: 'marketing',
    label: 'การตลาด',
    icon: 'fas fa-bullhorn',
    items: [
      { to: '/admin/broadcasts', icon: 'fas fa-bullhorn', label: 'ส่งข้อความถึงลูกค้า' },
      { to: '/admin/articles', icon: 'fas fa-pen-nib', label: 'บทความ/บล็อก' },
      { to: '/admin/content', icon: 'fas fa-file-lines', label: 'เนื้อหาหน้าเว็บ' },
      { to: '/admin/places', icon: 'fas fa-mountain', label: 'สถานที่/ฤดูกาล' },
      { to: '/admin/promotions', icon: 'fas fa-percent', label: 'โปรโมชั่น/ส่วนลด' },
      { to: '/admin/urgent-popup', icon: 'fas fa-fire', label: 'ป๊อปอัพทริปด่วน' },
      { to: '/admin/loyalty', icon: 'fas fa-coins', label: 'ระบบสะสมแต้ม' },
    ],
    isOpen: false
  },
  {
    id: 'operations',
    label: 'ปฏิบัติงาน',
    icon: 'fas fa-clipboard-check',
    items: [
      { to: '/admin/sos', icon: 'fas fa-tower-broadcast', label: 'ศูนย์เฝ้าระวัง SOS', badge: 'sos' },
      { to: '/admin/check-in', icon: 'fas fa-qrcode', label: 'เช็คอิน QR' },
      { to: '/admin/incidents', icon: 'fas fa-triangle-exclamation', label: 'แจ้งเหตุ/อุบัติเหตุ' },
      { to: '/admin/rentals', icon: 'fas fa-suitcase-rolling', label: 'อุปกรณ์เช่าที่ต้องเตรียม' },
      { to: '/admin/staff-assignments', icon: 'fas fa-user-check', label: 'มอบหมายสตาฟ' },
      { to: '/admin/staff-reviews', icon: 'fas fa-award', label: 'คะแนนรีวิวทีมงาน' },
      { to: '/admin/chat', icon: 'fas fa-comments', label: 'แชทกลุ่มทริป' },
      { to: '/admin/support', icon: 'fas fa-headset', label: 'ศูนย์ช่วยเหลือ' },
      { to: '/admin/announcements', icon: 'fas fa-bullhorn', label: 'ประกาศจากผู้จัด' },
      { to: '/admin/itinerary', icon: 'fas fa-list-check', label: 'กำหนดการเดินทาง' },
      { to: '/admin/schedule-photos', icon: 'fas fa-camera-retro', label: 'ภาพให้ลูกค้า' },
    ],
    isOpen: false
  },
  {
    id: 'system',
    label: 'ตั้งค่าระบบ',
    icon: 'fas fa-cog',
    items: [
      { to: '/admin/settings', icon: 'fas fa-sliders', label: 'ตั้งค่าระบบทั่วไป' },
      { to: '/admin/users', icon: 'fas fa-users-cog', label: 'ผู้ใช้งานระบบ' },
      { to: '/admin/hero-slides', icon: 'fas fa-images', label: 'สไลด์หน้าแรก' },
      { to: '/admin/gallery', icon: 'fas fa-photo-film', label: 'ภาพประทับใจ' },
    ],
    isOpen: false
  }
]);

const toggleGroup = (groupId) => {
  if (sidebarCollapsed.value) {
    sidebarCollapsed.value = false;
  }
  menuGroups.value = menuGroups.value.map(group => ({
    ...group,
    isOpen: group.id === groupId ? !group.isOpen : group.isOpen
  }));
};


const route = useRoute();

onMounted(() => {
  menuGroups.value.forEach(group => {
    const hasActiveItem = group.items.some(item => 
      item.to === '/admin' ? route.path === '/admin' : route.path.startsWith(item.to)
    );
    if (hasActiveItem) {
      group.isOpen = true;
    }
  });

  loadSosCount();
  sosTimer = setInterval(loadSosCount, 30000);
});

onBeforeUnmount(() => {
  if (sosTimer) clearInterval(sosTimer);
});

const handleLogout = async () => {
  await auth.logout();
  router.push('/login');
};
</script>

<style scoped>
.admin-layout {
  display: flex;
  min-height: 100vh;
  background: #F5F5F5;
}

/* ─── Sidebar ─────────────────────────── */
.admin-sidebar {
  width: 260px;
  background: #ffffff;
  border-right: 1px solid #e5e7eb;
  display: flex;
  flex-direction: column;
  transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  position: fixed;
  top: 0;
  left: 0;
  bottom: 0;
  z-index: 100;
  overflow-x: hidden;
}

.admin-sidebar.collapsed {
  width: 72px;
}

.sidebar-header {
  padding: 24px 20px;
  border-bottom: 1px solid #e5e7eb;
}

.brand {
  display: flex;
  align-items: center;
  gap: 12px;
}

.brand-collapsed {
  justify-content: center;
}

.brand-icon {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: #2d7a4f;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-size: 18px;
  flex-shrink: 0;
}

.brand-title {
  font-family: 'Playfair Display', serif;
  font-size: 20px;
  font-weight: 700;
  color: #111827;
  margin: 0;
  line-height: 1.2;
}

.brand-subtitle {
  font-size: 11px;
  color: #6b7280;
  text-transform: uppercase;
  letter-spacing: 1.5px;
}

/* ─── Navigation ──────────────────────── */
.sidebar-nav {
  flex: 1;
  padding: 16px 12px;
  display: flex;
  flex-direction: column;
  gap: 2px;
  overflow-y: auto;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 10px 16px;
  border-radius: 8px;
  color: #6b7280;
  text-decoration: none;
  font-size: 14px;
  font-weight: 500;
  transition: all 0.15s ease;
  white-space: nowrap;
}

.sub-item {
  padding-left: 44px;
  font-size: 13.5px;
  margin-bottom: 2px;
}

.nav-item i {
  width: 20px;
  text-align: center;
  font-size: 15px;
  flex-shrink: 0;
}

.nav-item:hover {
  color: #2d7a4f;
  background: #F5F5F5;
}

.nav-item.active {
  color: #2d7a4f;
  background: #EEEEEE;
  font-weight: 600;
}

/* ─── Group Styles ─────────────────────── */
.nav-group {
  margin-bottom: 4px;
}

.group-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  cursor: pointer;
  border-radius: 8px;
  color: #374151;
  transition: all 0.2s;
  user-select: none;
}

.group-header:hover {
  background: #f3f4f6;
}

.group-title-wrapper {
  display: flex;
  align-items: center;
  gap: 14px;
}

.group-title-wrapper i {
  width: 20px;
  text-align: center;
  font-size: 16px;
  color: #6b7280;
}

.group-header span {
  font-size: 14px;
  font-weight: 600;
  color: #4b5563;
}

.chevron {
  font-size: 10px;
  color: #9ca3af;
  transition: transform 0.3s ease;
}

.chevron.rotated {
  transform: rotate(90deg);
}

.group-items {
  margin-top: 4px;
  display: flex;
  flex-direction: column;
}

.collapsed-group-items {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  margin-top: 4px;
  border-bottom: 1px solid #f3f4f6;
  padding-bottom: 8px;
}

.collapsed-sub-item {
  padding: 10px 0;
  width: 100%;
  justify-content: center;
}

/* ─── SOS badge ───────────────────────── */
.nav-badge {
  margin-left: auto;
  min-width: 20px;
  padding: 1px 6px;
  border-radius: 999px;
  background: #dc2626;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  text-align: center;
  animation: badge-pulse 1.6s ease-in-out infinite;
}

.collapsed-badge {
  position: absolute;
  top: 4px;
  right: 12px;
  margin-left: 0;
}

.collapsed-sub-item {
  position: relative;
}

@keyframes badge-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.45; }
}

.sidebar-footer {
  padding: 16px 12px;
  border-top: 1px solid #e5e7eb;
}

.back-link {
  color: #9ca3af !important;
}

.back-link:hover {
  color: #2d7a4f !important;
  background: #F5F5F5 !important;
}

/* ─── Main Area ───────────────────────── */
.admin-main {
  flex: 1;
  margin-left: 260px;
  transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

.admin-main.expanded {
  margin-left: 72px;
}

/* ─── Top Bar ─────────────────────────── */
.admin-topbar {
  height: 64px;
  background: #ffffff;
  border-bottom: 1px solid #e5e7eb;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 24px;
  position: sticky;
  top: 0;
  z-index: 50;
}

.toggle-btn {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  border: 1px solid #e5e7eb;
  background: transparent;
  color: #6b7280;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.15s;
}

.toggle-btn:hover {
  background: #EEEEEE;
  color: #374151;
}

.topbar-right {
  display: flex;
  align-items: center;
  gap: 16px;
}

.admin-user {
  display: flex;
  align-items: center;
  gap: 10px;
}

.user-avatar {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: #2d7a4f;
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  font-weight: 700;
  font-size: 14px;
}

.user-info {
  display: flex;
  flex-direction: column;
}

.user-name {
  font-size: 13px;
  font-weight: 600;
  color: #111827;
}

.user-role {
  font-size: 11px;
  color: #6b7280;
}

.logout-btn {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  border: 1px solid #fce8e8;
  background: transparent;
  color: #dc2626;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.15s;
}

.logout-btn:hover {
  background: #fef2f2;
}

/* ─── Content ─────────────────────────── */
.admin-content {
  flex: 1;
  padding: 28px;
}

/* ─── Responsive ──────────────────────── */
@media (max-width: 768px) {
  .admin-sidebar {
    width: 72px;
  }
  .admin-sidebar .brand-title,
  .admin-sidebar .brand-subtitle,
  .admin-sidebar .nav-item span,
  .admin-sidebar .back-link span {
    display: none;
  }
  .admin-main {
    margin-left: 72px;
  }
  .admin-content {
    padding: 16px;
  }
}
</style>
