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
import MyStaffTripsPage from '../pages/MyStaffTripsPage.vue';
import LoyaltyPage from '../pages/LoyaltyPage.vue';
import NotificationsPage from '../pages/NotificationsPage.vue';
import PrivacyPage from '../pages/PrivacyPage.vue';
import TermsPage from '../pages/TermsPage.vue';
import ContactPage from '../pages/ContactPage.vue';
import ProfilePage from '../pages/ProfilePage.vue';
import SocialCallbackPage from '../pages/SocialCallbackPage.vue';
import ReviewsPage from '../pages/ReviewsPage.vue';
import BookingGuidePage from '../pages/BookingGuidePage.vue';
import FAQPage from '../pages/FAQPage.vue';



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
import AdminStaffAssignments from '../pages/admin/StaffAssignmentsPage.vue';
import AdminCategories from '../pages/admin/CategoriesPage.vue';
import AdminInquiries from '../pages/admin/InquiriesPage.vue';
import AdminTripEdit from '../pages/admin/TripEditPage.vue';


const routes = [
  // ── Public / Customer Routes ──
  {
    path: '/',
    name: 'home',
    component: HomePage,
    meta: {
      title: 'แพลตฟอร์มจองและจัดทริปเที่ยวทั่วไทย เดินป่า ดำน้ำตื้น เช่ารถตู้นำเที่ยว',
      description: 'ลุยเลเขา แพลตฟอร์มจองและจัดทริปเที่ยวทั่วประเทศไทย บริการเดินป่า ดำน้ำตื้น เช่ารถตู้นำเที่ยว ตอบโจทย์คนรักธรรมชาติ จองง่าย ปลอดภัย ใบอนุญาต 12/03773',
      ogType: 'website'
    }
  },
  {
    path: '/trips',
    name: 'trips',
    component: TripsPage,
    meta: {
      title: 'ค้นหาทริปทั้งหมด | เดินป่า ดำน้ำตื้น เช่ารถตู้',
      description: 'รวมทริปท่องเที่ยวทั่วประเทศไทย ทริปเดินป่าภูกระดึง ภูสอยดาว เขาช้างเผือก ทริปดำน้ำตื้นดูปะการัง และบริการเช่ารถตู้นำเที่ยว VIP พร้อมคนขับ จองออนไลน์ได้เลย',
      ogType: 'website'
    }
  },
  {
    path: '/trips/:slug',
    name: 'trip-detail',
    component: TripDetailPage,
    meta: {
      ogType: 'product'
    }
  },
  { path: '/booking/:scheduleId', name: 'booking', component: BookingPage, meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/payment/:bookingRef', name: 'payment', component: PaymentPage, meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/confirmation/:bookingRef', name: 'confirmation', component: ConfirmationPage, meta: { robots: 'noindex, nofollow' } },
  { path: '/login', name: 'login', component: LoginPage, meta: { title: 'เข้าสู่ระบบ', description: 'เข้าสู่ระบบลุยเลเขา เพื่อจองทริปเดินป่า ดำน้ำตื้น และเช่ารถตู้นำเที่ยว', robots: 'noindex, follow' } },
  { path: '/register', name: 'register', component: RegisterPage, meta: { title: 'สมัครสมาชิก', description: 'สมัครสมาชิกลุยเลเขา เพื่อรับสิทธิพิเศษและจองทริปได้ง่ายขึ้น', robots: 'noindex, follow' } },
  { path: '/my-bookings', name: 'my-bookings', component: MyBookingsPage, meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/my-reviews', name: 'my-reviews', component: MyReviewsPage, meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/my-staff-trips', name: 'my-staff-trips', component: MyStaffTripsPage, meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/loyalty', name: 'loyalty', component: LoyaltyPage, meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/notifications', name: 'notifications', component: NotificationsPage, meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  {
    path: '/about',
    name: 'about',
    component: AboutPage,
    meta: {
      title: 'เกี่ยวกับเรา',
      description: 'ทำความรู้จักกับลุยเลเขา ทีมงานผู้อยู่เบื้องหลังการจัดทริปเที่ยวทั่วไทย เดินป่า ดำน้ำตื้น เช่ารถตู้ ที่ใส่ใจในทุกรายละเอียด ใบอนุญาตนำเที่ยว 12/03773',
      ogType: 'website'
    }
  },
  {
    path: '/goal',
    name: 'goal',
    component: GoalPage,
    meta: {
      title: 'จุดมุ่งหมายของเรา',
      description: 'เป้าหมายของลุยเลเขาคือการทำให้ทุกคนออกไปเที่ยวธรรมชาติได้ง่ายขึ้น ปลอดภัย และมีความสุขในทุกการเดินทาง',
      ogType: 'website'
    }
  },
  {
    path: '/problem',
    name: 'problem',
    component: ProblemPage,
    meta: {
      title: 'แจ้งปัญหาการใช้งาน',
      description: 'แจ้งปัญหาเกี่ยวกับการจองทริป การชำระเงิน หรือการใช้งานเว็บไซต์ลุยเลเขา ทีมงานพร้อมช่วยเหลือ',
      robots: 'noindex, follow'
    }
  },
  {
    path: '/privacy',
    name: 'privacy',
    component: PrivacyPage,
    meta: {
      title: 'นโยบายความเป็นส่วนตัว',
      description: 'นโยบายความเป็นส่วนตัวของลุยเลเขา เราให้ความสำคัญกับการปกป้องข้อมูลส่วนบุคคลของผู้ใช้งานทุกท่าน'
    }
  },
  {
    path: '/terms',
    name: 'terms',
    component: TermsPage,
    meta: {
      title: 'เงื่อนไขการให้บริการ',
      description: 'เงื่อนไขการให้บริการของลุยเลเขา ข้อกำหนดการจองทริป การยกเลิก การคืนเงิน และข้อตกลงในการใช้บริการ'
    }
  },
  {
    path: '/reviews',
    name: 'reviews',
    component: ReviewsPage,
    meta: {
      title: 'รีวิวจากนักเดินทาง',
      description: 'อ่านรีวิวจากนักเดินทางที่เคยไปทริปกับลุยเลเขา ประสบการณ์จริงจากผู้ใช้งาน ทริปเดินป่า ดำน้ำตื้น เช่ารถตู้นำเที่ยว',
      ogType: 'website'
    }
  },
  {
    path: '/how-to-book',
    name: 'how-to-book',
    component: BookingGuidePage,
    meta: {
      title: 'วิธีการจองทริป',
      description: 'คู่มือวิธีการจองทริปกับลุยเลเขา ขั้นตอนง่ายๆ เลือกทริป เลือกวัน จ่ายเงิน รอรับการยืนยัน พร้อมชำระผ่าน PromptPay',
      ogType: 'article'
    }
  },
  {
    path: '/faq',
    name: 'faq',
    component: FAQPage,
    meta: {
      title: 'คำถามที่พบบ่อย (FAQ)',
      description: 'รวมคำถามที่พบบ่อยเกี่ยวกับการจองทริปลุยเลเขา การยกเลิก การคืนเงิน สิ่งที่ต้องเตรียม ความปลอดภัย และอื่นๆ',
      ogType: 'article'
    }
  },
  {
    path: '/contact',
    name: 'contact',
    component: ContactPage,
    meta: {
      title: 'ติดต่อเรา',
      description: 'ติดต่อลุยเลเขา สอบถามเรื่องจองทริปเดินป่า ดำน้ำตื้น เช่ารถตู้ โทร 062-612-6006 LINE @luilaykhao อีเมล luilaykhao.info@gmail.com',
      ogType: 'website'
    }
  },
  { path: '/profile', name: 'profile', component: ProfilePage, meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/auth/social/callback', name: 'social-callback', component: SocialCallbackPage, meta: { robots: 'noindex, nofollow' } },



  // ── Admin Routes ──
  {
    path: '/admin',
    component: AdminLayout,
    meta: { requiresAuth: true, requiresAdmin: true, robots: 'noindex, nofollow' },
    children: [
      { path: '', name: 'admin-dashboard', component: AdminDashboard },
      { path: 'trips', name: 'admin-trips', component: AdminTrips },
      { path: 'trips/create', name: 'admin-trip-create', component: AdminTripEdit },
      { path: 'trips/:id/edit', name: 'admin-trip-edit', component: AdminTripEdit },
      { path: 'schedules', name: 'admin-schedules', component: AdminSchedules },
      { path: 'bookings', name: 'admin-bookings', component: AdminBookings },
      { path: 'vehicles', name: 'admin-vehicles', component: AdminVehicles },
      { path: 'users', name: 'admin-users', component: AdminUsers },
      { path: 'staff-assignments', name: 'admin-staff-assignments', component: AdminStaffAssignments },
      { path: 'calendar', name: 'admin-calendar', component: AdminCalendar },
      { path: 'customers', name: 'admin-customers', component: AdminCustomers },
      { path: 'maintenance', name: 'admin-maintenance', component: AdminMaintenance },
      { path: 'reports', name: 'admin-reports', component: AdminReports },
      { path: 'check-in', name: 'admin-checkin', component: AdminCheckIn },
      { path: 'reviews', name: 'admin-reviews', component: AdminReviews },
      { path: 'loyalty', name: 'admin-loyalty', component: AdminLoyalty },
      { path: 'analytics', name: 'admin-analytics', component: AdminAnalytics },
      { path: 'tracking', name: 'admin-tracking', component: AdminTracking },
      { path: 'categories', name: 'admin-categories', component: AdminCategories },
      { path: 'inquiries', name: 'admin-inquiries', component: AdminInquiries },
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
