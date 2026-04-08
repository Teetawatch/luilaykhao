import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

import HomePage from '../pages/HomePage.vue';
import TripsPage from '../pages/TripsPage.vue';
import TripDetailPage from '../pages/TripDetailPage.vue';
import BookingPage from '../pages/BookingPage.vue';
import PaymentPage from '../pages/PaymentPage.vue';
import ConfirmationPage from '../pages/ConfirmationPage.vue';
import LoginPage from '../pages/LoginPage.vue';
import RegisterPage from '../pages/RegisterPage.vue';
import MyBookingsPage from '../pages/MyBookingsPage.vue';
import AboutPage from '../pages/AboutPage.vue';
import GoalPage from '../pages/GoalPage.vue';
import ProblemPage from '../pages/ProblemPage.vue';
import MyReviewsPage from '../pages/MyReviewsPage.vue';
import LoyaltyPage from '../pages/LoyaltyPage.vue';
import NotificationsPage from '../pages/NotificationsPage.vue';
import PrivacyPage from '../pages/PrivacyPage.vue';
import TermsPage from '../pages/TermsPage.vue';
import ContactPage from '../pages/ContactPage.vue';
import ProfilePage from '../pages/ProfilePage.vue';



// Admin
import AdminLayout from '../components/AdminLayout.vue';
import AdminDashboard from '../pages/admin/DashboardPage.vue';
import AdminTrips from '../pages/admin/TripsPage.vue';
import AdminSchedules from '../pages/admin/SchedulesPage.vue';
import AdminBookings from '../pages/admin/BookingsPage.vue';
import AdminVehicles from '../pages/admin/VehiclesPage.vue';
import AdminUsers from '../pages/admin/UsersPage.vue';
import AdminCalendar from '../pages/admin/CalendarPage.vue';
import AdminCustomers from '../pages/admin/CustomersPage.vue';
import AdminMaintenance from '../pages/admin/MaintenancePage.vue';
import AdminReports from '../pages/admin/ReportsPage.vue';
import AdminCheckIn from '../pages/admin/CheckInPage.vue';
import AdminReviews from '../pages/admin/ReviewsPage.vue';
import AdminLoyalty from '../pages/admin/LoyaltyPage.vue';
import AdminAnalytics from '../pages/admin/AnalyticsPage.vue';
import AdminTracking from '../pages/admin/TrackingPage.vue';

const routes = [
  // ── Public / Customer Routes ──
  { path: '/', name: 'home', component: HomePage },
  { path: '/trips', name: 'trips', component: TripsPage },
  { path: '/trips/:slug', name: 'trip-detail', component: TripDetailPage },
  { path: '/booking/:scheduleId', name: 'booking', component: BookingPage, meta: { requiresAuth: true } },
  { path: '/payment/:bookingRef', name: 'payment', component: PaymentPage, meta: { requiresAuth: true } },
  { path: '/confirmation/:bookingRef', name: 'confirmation', component: ConfirmationPage },
  { path: '/login', name: 'login', component: LoginPage },
  { path: '/register', name: 'register', component: RegisterPage },
  { path: '/my-bookings', name: 'my-bookings', component: MyBookingsPage, meta: { requiresAuth: true } },
  { path: '/my-reviews', name: 'my-reviews', component: MyReviewsPage, meta: { requiresAuth: true } },
  { path: '/loyalty', name: 'loyalty', component: LoyaltyPage, meta: { requiresAuth: true } },
  { path: '/notifications', name: 'notifications', component: NotificationsPage, meta: { requiresAuth: true } },
  { path: '/about', name: 'about', component: AboutPage },
  { path: '/goal', name: 'goal', component: GoalPage },
  { path: '/problem', name: 'problem', component: ProblemPage },
  { path: '/privacy', name: 'privacy', component: PrivacyPage },
  { path: '/terms', name: 'terms', component: TermsPage },
  { path: '/contact', name: 'contact', component: ContactPage },
  { path: '/profile', name: 'profile', component: ProfilePage, meta: { requiresAuth: true } },



  // ── Admin Routes ──
  {
    path: '/admin',
    component: AdminLayout,
    meta: { requiresAuth: true, requiresAdmin: true },
    children: [
      { path: '', name: 'admin-dashboard', component: AdminDashboard },
      { path: 'trips', name: 'admin-trips', component: AdminTrips },
      { path: 'schedules', name: 'admin-schedules', component: AdminSchedules },
      { path: 'bookings', name: 'admin-bookings', component: AdminBookings },
      { path: 'vehicles', name: 'admin-vehicles', component: AdminVehicles },
      { path: 'users', name: 'admin-users', component: AdminUsers },
      { path: 'calendar', name: 'admin-calendar', component: AdminCalendar },
      { path: 'customers', name: 'admin-customers', component: AdminCustomers },
      { path: 'maintenance', name: 'admin-maintenance', component: AdminMaintenance },
      { path: 'reports', name: 'admin-reports', component: AdminReports },
      { path: 'check-in', name: 'admin-checkin', component: AdminCheckIn },
      { path: 'reviews', name: 'admin-reviews', component: AdminReviews },
      { path: 'loyalty', name: 'admin-loyalty', component: AdminLoyalty },
      { path: 'analytics', name: 'admin-analytics', component: AdminAnalytics },
      { path: 'tracking', name: 'admin-tracking', component: AdminTracking },
    ],
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior() {
    return { top: 0 };
  },
});

router.beforeEach((to, from, next) => {
  const auth = useAuthStore();

  if (to.meta.requiresAuth && !auth.isLoggedIn) {
    next({ name: 'login', query: { redirect: to.fullPath } });
    return;
  }

  if (to.meta.requiresAdmin) {
    const userRoles = auth.user?.roles?.map(r => typeof r === 'string' ? r : r.name) || [];
    if (!userRoles.includes('admin') && !userRoles.includes('operator')) {
      next({ name: 'home' });
      return;
    }
  }

  next();
});

export default router;
