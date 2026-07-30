import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

// Home stays eagerly bundled so the most common landing renders without a
// second round-trip. Every other page is lazy-loaded (route-level code
// splitting) so a first-time visitor no longer downloads the entire admin
// panel + all customer pages up front.
import HomePage from '../pages/HomePage.vue';

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
    component: () => import('../pages/TripsPage.vue'),
    meta: {
      title: 'ค้นหาทริปทั้งหมด | เดินป่า ดำน้ำตื้น เช่ารถตู้',
      description: 'รวมทริปท่องเที่ยวทั่วประเทศไทย ทริปเดินป่าภูกระดึง ภูสอยดาว เขาช้างเผือก ทริปดำน้ำตื้นดูปะการัง และบริการเช่ารถตู้นำเที่ยว VIP พร้อมคนขับ จองออนไลน์ได้เลย',
      ogType: 'website'
    }
  },
  {
    path: '/find',
    name: 'trip-finder',
    component: () => import('../pages/TripFinderPage.vue'),
    meta: {
      title: 'ค้นหาทริปที่ใช่ | ตอบไม่กี่ข้อ เจอทริปที่ชอบ',
      description: 'ตอบคำถามสั้นๆ เรื่องประเภทกิจกรรม ระดับความท้าทาย และจำนวนวัน แล้วให้เราแนะนำทริปที่ใช่สำหรับคุณ',
      ogType: 'website'
    }
  },
  {
    path: '/assistant',
    name: 'assistant',
    component: () => import('../pages/AssistantPage.vue'),
    meta: {
      title: 'ถามหาทริปที่ใช่ | ผู้ช่วยวางทริปลุยเลเขา',
      description: 'บอกงบ จำนวนวัน และระดับที่ไหว แล้วให้ผู้ช่วยหาทริปที่เปิดจองอยู่จริงมาให้',
      ogType: 'website'
    }
  },
  {
    path: '/explore',
    name: 'explore-map',
    component: () => import('../pages/ExploreMapPage.vue'),
    meta: {
      title: 'สำรวจทริปบนแผนที่ | ดูว่าทริปไหนอยู่ตรงไหนของไทย',
      description: 'เลือกทริปจากตำแหน่งจริงบนแผนที่ประเทศไทย กรองตามภูมิภาค ระดับความยาก และเดือนที่อยากไป',
      ogType: 'website'
    }
  },
  {
    path: '/trips/:slug',
    name: 'trip-detail',
    component: () => import('../pages/TripDetailPage.vue'),
    meta: {
      ogType: 'product'
    }
  },
  { path: '/booking/:scheduleId', name: 'booking', component: () => import('../pages/BookingPage.vue'), meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/payment/:bookingRef', name: 'payment', component: () => import('../pages/PaymentPage.vue'), meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/confirmation/:bookingRef', name: 'confirmation', component: () => import('../pages/ConfirmationPage.vue'), meta: { robots: 'noindex, nofollow' } },
  { path: '/login', name: 'login', component: () => import('../pages/LoginPage.vue'), meta: { title: 'เข้าสู่ระบบ', description: 'เข้าสู่ระบบลุยเลเขา เพื่อจองทริปเดินป่า ดำน้ำตื้น และเช่ารถตู้นำเที่ยว', robots: 'noindex, follow' } },
  { path: '/register', name: 'register', component: () => import('../pages/RegisterPage.vue'), meta: { title: 'สมัครสมาชิก', description: 'สมัครสมาชิกลุยเลเขา เพื่อรับสิทธิพิเศษและจองทริปได้ง่ายขึ้น', robots: 'noindex, follow' } },
  { path: '/my-bookings', name: 'my-bookings', component: () => import('../pages/MyBookingsPage.vue'), meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/installment-payment/:bookingRef', name: 'installment-payment', component: () => import('../pages/InstallmentPaymentPage.vue'), meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/chat/schedule/:scheduleId', name: 'trip-chat', component: () => import('../pages/TripChatPage.vue'), meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/my-reviews', name: 'my-reviews', component: () => import('../pages/MyReviewsPage.vue'), meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/my-staff-trips', name: 'my-staff-trips', component: () => import('../pages/MyStaffTripsPage.vue'), meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/loyalty', name: 'loyalty', component: () => import('../pages/LoyaltyPage.vue'), meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/referral', name: 'referral', component: () => import('../pages/ReferralPage.vue'), meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/notifications', name: 'notifications', component: () => import('../pages/NotificationsPage.vue'), meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  {
    path: '/about',
    name: 'about',
    component: () => import('../pages/AboutPage.vue'),
    meta: {
      title: 'เกี่ยวกับเรา',
      description: 'ทำความรู้จักกับลุยเลเขา ทีมงานผู้อยู่เบื้องหลังการจัดทริปเที่ยวทั่วไทย เดินป่า ดำน้ำตื้น เช่ารถตู้ ที่ใส่ใจในทุกรายละเอียด ใบอนุญาตนำเที่ยว 12/03773',
      ogType: 'website'
    }
  },
  {
    path: '/goal',
    name: 'goal',
    component: () => import('../pages/GoalPage.vue'),
    meta: {
      title: 'จุดมุ่งหมายของเรา',
      description: 'เป้าหมายของลุยเลเขาคือการทำให้ทุกคนออกไปเที่ยวธรรมชาติได้ง่ายขึ้น ปลอดภัย และมีความสุขในทุกการเดินทาง',
      ogType: 'website'
    }
  },
  {
    path: '/problem',
    name: 'problem',
    component: () => import('../pages/ProblemPage.vue'),
    meta: {
      title: 'แจ้งปัญหาการใช้งาน',
      description: 'แจ้งปัญหาเกี่ยวกับการจองทริป การชำระเงิน หรือการใช้งานเว็บไซต์ลุยเลเขา ทีมงานพร้อมช่วยเหลือ',
      robots: 'noindex, follow'
    }
  },
  {
    path: '/privacy',
    name: 'privacy',
    component: () => import('../pages/PrivacyPage.vue'),
    meta: {
      title: 'นโยบายความเป็นส่วนตัว',
      description: 'นโยบายความเป็นส่วนตัวของลุยเลเขา เราให้ความสำคัญกับการปกป้องข้อมูลส่วนบุคคลของผู้ใช้งานทุกท่าน'
    }
  },
  {
    path: '/terms',
    name: 'terms',
    component: () => import('../pages/TermsPage.vue'),
    meta: {
      title: 'เงื่อนไขการให้บริการ',
      description: 'เงื่อนไขการให้บริการของลุยเลเขา ข้อกำหนดการจองทริป การยกเลิก การคืนเงิน และข้อตกลงในการใช้บริการ'
    }
  },
  {
    path: '/reviews',
    name: 'reviews',
    component: () => import('../pages/ReviewsPage.vue'),
    meta: {
      title: 'รีวิวจากนักเดินทาง',
      description: 'อ่านรีวิวจากนักเดินทางที่เคยไปทริปกับลุยเลเขา ประสบการณ์จริงจากผู้ใช้งาน ทริปเดินป่า ดำน้ำตื้น เช่ารถตู้นำเที่ยว',
      ogType: 'website'
    }
  },
  {
    path: '/gallery',
    name: 'gallery',
    component: () => import('../pages/GalleryPage.vue'),
    meta: {
      title: 'รูปจากคนที่ไปมาแล้ว | ลุยเลเขา',
      description: 'รูปที่ผู้ร่วมทริปถ่ายเองและแนบมากับรีวิว ทั้งเดินป่า ดำน้ำตื้น และทริปธรรมชาติทั่วไทย ไม่ผ่านการคัดของทีมงาน',
      ogType: 'website'
    }
  },
  {
    path: '/how-to-book',
    name: 'how-to-book',
    component: () => import('../pages/BookingGuidePage.vue'),
    meta: {
      title: 'วิธีการจองทริป',
      description: 'คู่มือวิธีการจองทริปกับลุยเลเขา ขั้นตอนง่ายๆ เลือกทริป เลือกวัน จ่ายเงิน รอรับการยืนยัน พร้อมชำระผ่าน PromptPay',
      ogType: 'article'
    }
  },
  {
    path: '/faq',
    name: 'faq',
    component: () => import('../pages/FAQPage.vue'),
    meta: {
      title: 'คำถามที่พบบ่อย (FAQ)',
      description: 'รวมคำถามที่พบบ่อยเกี่ยวกับการจองทริปลุยเลเขา การยกเลิก การคืนเงิน สิ่งที่ต้องเตรียม ความปลอดภัย และอื่นๆ',
      ogType: 'article'
    }
  },
  {
    path: '/contact',
    name: 'contact',
    component: () => import('../pages/ContactPage.vue'),
    meta: {
      title: 'ติดต่อเรา',
      description: 'ติดต่อลุยเลเขา สอบถามเรื่องจองทริปเดินป่า ดำน้ำตื้น เช่ารถตู้ โทร 062-612-6006 LINE @luilaykhao อีเมล luilaykhao.info@gmail.com',
      ogType: 'website'
    }
  },
  // ── สถานที่ + ฤดูกาล + คู่มือ (เนื้อหา ไม่ใช่หน้าขาย) ──
  {
    path: '/places',
    name: 'places',
    component: () => import('../pages/PlacesPage.vue'),
    meta: {
      title: 'สถานที่ธรรมชาติในไทย | ภูเขา เกาะ อุทยาน',
      description: 'ข้อมูลภูเขา เกาะ น้ำตก และอุทยานทั่วไทย ความสูง ระยะเดิน ระดับความยาก ช่วงที่ควรไปและช่วงที่ปิด อ่านได้แม้ยังไม่มีรอบเปิดจอง',
      ogType: 'website'
    }
  },
  {
    path: '/places/:slug',
    name: 'place-detail',
    component: () => import('../pages/PlaceDetailPage.vue'),
    meta: { ogType: 'article' }
  },
  {
    path: '/seasons',
    name: 'seasons',
    component: () => import('../pages/SeasonsPage.vue'),
    meta: {
      title: 'เดือนไหนไปไหนดี | ปฏิทินธรรมชาติไทยทั้งปี',
      description: 'ปฏิทิน 12 เดือนของธรรมชาติไทย เดือนนี้ที่ไหนอยู่ในช่วงพีค ที่ไหนปิดฟื้นฟู ใช้วางแผนเที่ยวล่วงหน้าได้ทั้งปี',
      ogType: 'article'
    }
  },
  {
    path: '/difficulty',
    name: 'difficulty',
    component: () => import('../pages/DifficultyPage.vue'),
    meta: {
      title: 'ระดับความยากเดินป่าหมายถึงอะไร',
      description: 'อธิบายเกณฑ์ระดับความยากของทริปเดินป่า สายชิล ปานกลาง สายโหด ต่างกันยังไง ใช้ระยะทางและความสูงที่ต้องไต่เท่าไหร่ และจะรู้ได้ยังไงว่าเราไหว',
      ogType: 'article'
    }
  },
  {
    path: '/checklist',
    name: 'checklist',
    component: () => import('../pages/ChecklistPage.vue'),
    meta: {
      title: 'เช็คลิสต์ของที่ต้องเตรียม | เดินป่า ทะเล แคมป์ปิ้ง',
      description: 'เช็คลิสต์อุปกรณ์เดินป่า ดำน้ำตื้น และแคมป์ปิ้ง แยกตามฤดูและจำนวนวัน ติ๊กได้ ปรินต์ได้ ใช้ฟรีแม้ไม่ได้จองทริปกับเรา',
      ogType: 'article'
    }
  },
  {
    path: '/feed',
    name: 'feed',
    component: () => import('../pages/FeedPage.vue'),
    meta: {
      title: 'ฟีดจากนักเดินทาง | รูปจริงจากคนที่ไปมาแล้ว',
      description: 'รูปที่ผู้ร่วมทริปโพสต์เองหลังกลับจากทริป ไม่ผ่านการคัดของทีมงาน ดูบรรยากาศจริงก่อนตัดสินใจ',
      ogType: 'website'
    }
  },

  // ── ของสะสม/สถิติส่วนตัว ──
  { path: '/passport', name: 'passport', component: () => import('../pages/PassportPage.vue'), meta: { requiresAuth: true, title: 'สมุดสะสมการเดินทาง', robots: 'noindex, nofollow' } },
  { path: '/my-tracks', name: 'my-tracks', component: () => import('../pages/MyTracksPage.vue'), meta: { requiresAuth: true, title: 'บันทึกการเดินของฉัน', robots: 'noindex, nofollow' } },
  { path: '/recap/:ref', name: 'recap', component: () => import('../pages/RecapPage.vue'), meta: { requiresAuth: true, robots: 'noindex, nofollow' } },

  // ── ไปด้วยกัน (group plans) ──
  { path: '/group-plans', name: 'group-plans', component: () => import('../pages/MyGroupPlansPage.vue'), meta: { requiresAuth: true, title: 'กลุ่มไปด้วยกัน', robots: 'noindex, nofollow' } },
  { path: '/group/:code', name: 'group-plan', component: () => import('../pages/GroupPlanPage.vue'), meta: { requiresAuth: true, robots: 'noindex, nofollow' } },

  { path: '/profile', name: 'profile', component: () => import('../pages/ProfilePage.vue'), meta: { requiresAuth: true, robots: 'noindex, nofollow' } },
  { path: '/support', name: 'support', component: () => import('../pages/SupportPage.vue'), meta: { requiresAuth: true, title: 'ศูนย์ช่วยเหลือ', robots: 'noindex, nofollow' } },
  { path: '/auth/social/callback', name: 'social-callback', component: () => import('../pages/SocialCallbackPage.vue'), meta: { robots: 'noindex, nofollow' } },



  // ── Admin Routes ──
  {
    path: '/admin',
    component: () => import('../components/AdminLayout.vue'),
    meta: { requiresAuth: true, requiresAdmin: true, robots: 'noindex, nofollow' },
    children: [
      { path: '', name: 'admin-dashboard', component: () => import('../pages/admin/DashboardPage.vue') },
      { path: 'trips', name: 'admin-trips', component: () => import('../pages/admin/TripsPage.vue') },
      { path: 'trips/create', name: 'admin-trip-create', component: () => import('../pages/admin/TripEditPage.vue') },
      { path: 'trips/:id/edit', name: 'admin-trip-edit', component: () => import('../pages/admin/TripEditPage.vue') },
      { path: 'van-trips', name: 'admin-van-trips', component: () => import('../pages/admin/VanTripsPage.vue') },
      { path: 'van-trips/create', name: 'admin-van-trip-create', component: () => import('../pages/admin/TripEditPage.vue') },
      { path: 'van-trips/:id/edit', name: 'admin-van-trip-edit', component: () => import('../pages/admin/TripEditPage.vue') },
      { path: 'schedules', name: 'admin-schedules', component: () => import('../pages/admin/SchedulesPage.vue') },
      { path: 'manual-booking', name: 'admin-manual-booking', component: () => import('../pages/admin/ManualBookingPage.vue') },
      { path: 'bookings', name: 'admin-bookings', component: () => import('../pages/admin/BookingsPage.vue') },
      { path: 'vehicles', name: 'admin-vehicles', component: () => import('../pages/admin/VehiclesPage.vue') },
      { path: 'drivers', name: 'admin-drivers', component: () => import('../pages/admin/DriversPage.vue') },
      { path: 'users', name: 'admin-users', component: () => import('../pages/admin/UsersPage.vue') },
      { path: 'staff-assignments', name: 'admin-staff-assignments', component: () => import('../pages/admin/StaffAssignmentsPage.vue') },
      { path: 'calendar', name: 'admin-calendar', component: () => import('../pages/admin/CalendarPage.vue') },
      { path: 'customers', name: 'admin-customers', component: () => import('../pages/admin/CustomersPage.vue') },
      { path: 'birthdate-followup', name: 'admin-birthdate-followup', component: () => import('../pages/admin/BirthdateFollowupPage.vue') },
      { path: 'maintenance', name: 'admin-maintenance', component: () => import('../pages/admin/MaintenancePage.vue') },
      { path: 'reports', name: 'admin-reports', component: () => import('../pages/admin/ReportsPage.vue') },
      { path: 'finance', name: 'admin-finance', component: () => import('../pages/admin/ProfitPage.vue') },
      { path: 'check-in', name: 'admin-checkin', component: () => import('../pages/admin/CheckInPage.vue') },
      { path: 'chat', name: 'admin-chat', component: () => import('../pages/admin/ChatPage.vue') },
      { path: 'support', name: 'admin-support', component: () => import('../pages/admin/SupportPage.vue') },
      { path: 'announcements', name: 'admin-announcements', component: () => import('../pages/admin/AnnouncementsPage.vue') },
      { path: 'itinerary', name: 'admin-itinerary', component: () => import('../pages/admin/ItineraryPage.vue') },
      { path: 'incidents', name: 'admin-incidents', component: () => import('../pages/admin/IncidentsPage.vue') },
      { path: 'sos', name: 'admin-sos', component: () => import('../pages/admin/SosPage.vue') },
      { path: 'action-queue', name: 'admin-action-queue', component: () => import('../pages/admin/ActionQueuePage.vue') },
      { path: 'broadcasts', name: 'admin-broadcasts', component: () => import('../pages/admin/BroadcastPage.vue') },
      { path: 'rentals', name: 'admin-rentals', component: () => import('../pages/admin/RentalsPage.vue') },
      { path: 'staff-reviews', name: 'admin-staff-reviews', component: () => import('../pages/admin/StaffReviewsPage.vue') },
      { path: 'settings', name: 'admin-settings', component: () => import('../pages/admin/SettingsPage.vue') },
      { path: 'reviews', name: 'admin-reviews', component: () => import('../pages/admin/ReviewsPage.vue') },
      { path: 'trip-posts', name: 'admin-trip-posts', component: () => import('../pages/admin/TripPostsModerationPage.vue') },
      { path: 'loyalty', name: 'admin-loyalty', component: () => import('../pages/admin/LoyaltyPage.vue') },
      { path: 'analytics', name: 'admin-analytics', component: () => import('../pages/admin/AnalyticsPage.vue') },
      { path: 'tracking', name: 'admin-tracking', component: () => import('../pages/admin/TrackingPage.vue') },
      { path: 'categories', name: 'admin-categories', component: () => import('../pages/admin/CategoriesPage.vue') },
      { path: 'inquiries', name: 'admin-inquiries', component: () => import('../pages/admin/InquiriesPage.vue') },
      { path: 'promotions', name: 'admin-promotions', component: () => import('../pages/admin/PromotionsPage.vue') },
      { path: 'urgent-popup', name: 'admin-urgent-popup', component: () => import('../pages/admin/UrgentPopupPage.vue') },
      { path: 'schedule-overview', name: 'admin-schedule-overview', component: () => import('../pages/admin/ScheduleOverviewPage.vue') },
      { path: 'flexi-price', name: 'admin-flexi-price', component: () => import('../pages/admin/FlexiPricePage.vue') },
      { path: 'hero-slides', name: 'admin-hero-slides', component: () => import('../pages/admin/HeroSlidesPage.vue') },
      { path: 'gallery', name: 'admin-gallery', component: () => import('../pages/admin/GalleryPage.vue') },
      { path: 'schedule-photos', name: 'admin-schedule-photos', component: () => import('../pages/admin/SchedulePhotosPage.vue') },
      { path: 'places', name: 'admin-places', component: () => import('../pages/admin/PlacesPage.vue') },
      { path: 'content', name: 'admin-content', component: () => import('../pages/admin/ContentPagesPage.vue') },
      { path: 'content/:key', name: 'admin-content-edit', component: () => import('../pages/admin/ContentEditPage.vue') },
      { path: 'articles', name: 'admin-articles', component: () => import('../pages/admin/ArticlesPage.vue') },
      { path: 'articles/create', name: 'admin-article-create', component: () => import('../pages/admin/ArticleEditPage.vue') },
      { path: 'articles/:id/edit', name: 'admin-article-edit', component: () => import('../pages/admin/ArticleEditPage.vue') },
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
