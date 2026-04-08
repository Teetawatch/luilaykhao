<template>
  <div class="admin-layout">
    <!-- Sidebar -->
    <aside class="admin-sidebar" :class="{ collapsed: sidebarCollapsed }">
      <div class="sidebar-header flex flex-col items-center">
        <img src="/images/logo.png" alt="TrailDive" class="w-18 h-18 object-contain" />
        <span v-if="!sidebarCollapsed" class="brand-subtitle mt-1">แผงควบคุมระบบ</span>
      </div>

      <nav class="sidebar-nav">
        <router-link
          v-for="item in menuItems"
          :key="item.to"
          :to="item.to"
          class="nav-item"
          :class="{ active: $route.path === item.to }"
        >
          <i :class="item.icon"></i>
          <span v-if="!sidebarCollapsed">{{ item.label }}</span>
        </router-link>
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
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const router = useRouter();
const auth = useAuthStore();
const sidebarCollapsed = ref(false);

const menuItems = [
  { to: '/admin', icon: 'fas fa-tachometer-alt', label: 'แดชบอร์ด' },
  { to: '/admin/analytics', icon: 'fas fa-chart-area', label: 'Analytics' },
  { to: '/admin/calendar', icon: 'fas fa-calendar', label: 'ปฏิทินทริป' },
  { to: '/admin/trips', icon: 'fas fa-route', label: 'จัดการทริป' },
  { to: '/admin/schedules', icon: 'fas fa-calendar-alt', label: 'รอบเดินทาง' },
  { to: '/admin/bookings', icon: 'fas fa-ticket-alt', label: 'การจอง' },
  { to: '/admin/customers', icon: 'fas fa-user-friends', label: 'จัดการลูกค้า' },
  { to: '/admin/vehicles', icon: 'fas fa-shuttle-van', label: 'ยานพาหนะ' },
  { to: '/admin/tracking', icon: 'fas fa-map-marked-alt', label: 'ติดตามรถ GPS' },
  { to: '/admin/maintenance', icon: 'fas fa-tools', label: 'บำรุงรักษา' },
  { to: '/admin/reviews', icon: 'fas fa-star', label: 'รีวิว' },
  { to: '/admin/loyalty', icon: 'fas fa-coins', label: 'สะสมแต้ม' },
  { to: '/admin/reports', icon: 'fas fa-chart-line', label: 'รายงาน' },
  { to: '/admin/check-in', icon: 'fas fa-qrcode', label: 'เช็คอิน QR' },
  { to: '/admin/users', icon: 'fas fa-users', label: 'ผู้ใช้งาน' },
];

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
