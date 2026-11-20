<template>
  <footer class="site-footer mt-auto bg-[var(--color-primary)] text-white/75">
    <!-- แถบติดต่อด่วน — แบบเว็บบริษัทนำเที่ยว: เบอร์โทรตัวใหญ่ + LINE ก่อนถึงคอลัมน์ลิงก์ -->
    <div class="border-b border-white/10">
      <div class="max-w-7xl mx-auto px-6 lg:px-8 py-8 flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div>
          <p class="text-xl md:text-2xl font-bold text-white">{{ $t('สอบถามข้อมูลและจองทริป') }}</p>
          <p class="mt-1 text-[15px] text-white/65">{{ $t('{supportHours} · ตอบทุกคำถามก่อนตัดสินใจ', { supportHours: supportHours() }) }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <a :href="supportPhoneHref()" class="footer-cta footer-cta--light">
            <span class="material-symbols-rounded icon-22" style="font-variation-settings:'FILL' 1">call</span>
            <span class="tabular-nums">{{ supportPhone() }}</span>
          </a>
          <a :href="supportLineUrl()" target="_blank" rel="noopener" class="footer-cta footer-cta--line">
            <i class="fa-brands fa-line" aria-hidden="true"></i>
            {{ $t('ติดต่อเราผ่านไลน์') }}
          </a>
        </div>
      </div>
    </div>

    <div class="max-w-7xl mx-auto px-6 lg:px-8 py-14">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-10 lg:gap-8">
        <!-- แบรนด์ -->
        <div class="lg:col-span-4">
          <router-link to="/" class="inline-flex items-center gap-3">
            <span class="w-16 h-16 rounded-2xl bg-white flex items-center justify-center p-1.5">
              <img src="/images/logo.png?v=2" :alt="$t('ลุยเลเขา')" class="w-full h-full object-contain" />
            </span>
            <span class="flex flex-col leading-none">
              <span class="text-2xl font-extrabold text-white">{{ $t('ลุยเลเขา') }}</span>
              <span class="mt-1.5 text-[13px] font-semibold text-white/60">Trip &amp; Travel</span>
            </span>
          </router-link>
          <p class="mt-5 text-[15px] leading-relaxed max-w-sm">
            {{ $t('บริษัทนำเที่ยวเดินป่า ดำน้ำ และรถตู้รับส่ง บอกระยะทาง ความสูงสะสม จุดรับ และเวลาออกของทุกรอบไว้ล่วงหน้า') }}
          </p>
          <div class="mt-6 flex items-center gap-2.5">
            <a
              v-for="social in socialLinks"
              :key="social.label"
              :href="social.href"
              target="_blank"
              rel="noopener"
              :aria-label="social.label"
              class="footer-social"
            >
              <i :class="social.icon" aria-hidden="true"></i>
            </a>
            <a :href="supportLineUrl()" target="_blank" rel="noopener" aria-label="LINE Official" class="footer-social">
              <i class="fa-brands fa-line" aria-hidden="true"></i>
            </a>
          </div>
        </div>

        <!-- ทริปของเรา -->
        <div class="lg:col-span-2">
          <h4 class="footer-heading">{{ $t('ทริปของเรา') }}</h4>
          <ul class="footer-list">
            <li><router-link to="/trips">{{ $t('ทริปทั้งหมด') }}</router-link></li>
            <template v-if="categories.length">
              <li v-for="cat in categories" :key="cat.id">
                <router-link :to="`/trips?type=${cat.slug}`">{{ cat.name }}</router-link>
              </li>
            </template>
            <template v-else-if="loading">
              <li v-for="i in 3" :key="i"><span class="block h-4 w-20 rounded bg-white/10 animate-pulse"></span></li>
            </template>
            <li><router-link to="/calendar">{{ $t('ปฏิทินทริป') }}</router-link></li>
            <li><router-link to="/stays">{{ $t('ที่พักชุมชน') }}</router-link></li>
          </ul>
        </div>

        <!-- ข้อมูลและบริการ -->
        <div class="lg:col-span-3">
          <h4 class="footer-heading">{{ $t('ข้อมูลและบริการ') }}</h4>
          <ul class="footer-list sm:columns-2 lg:columns-1 xl:columns-2 gap-6">
            <li><router-link to="/about">{{ $t('เกี่ยวกับเรา') }}</router-link></li>
            <li><router-link to="/how-to-book">{{ $t('วิธีการจอง') }}</router-link></li>
            <li><router-link to="/faq">{{ $t('คำถามที่พบบ่อย') }}</router-link></li>
            <li><router-link to="/reviews">{{ $t('รีวิวลูกค้า') }}</router-link></li>
            <li><router-link to="/gallery">{{ $t('รูปจากลูกค้า') }}</router-link></li>
            <li><a href="/blog">{{ $t('บทความ') }}</a></li>
            <li><router-link to="/places">{{ $t('สถานที่ธรรมชาติ') }}</router-link></li>
            <li><router-link to="/seasons">{{ $t('เดือนไหนไปไหนดี') }}</router-link></li>
            <li><router-link to="/checklist">{{ $t('เช็คลิสต์ของที่ต้องเตรียม') }}</router-link></li>
            <li><router-link to="/problem">{{ $t('แจ้งปัญหาการใช้งาน') }}</router-link></li>
          </ul>
        </div>

        <!-- ติดต่อเรา + แอป -->
        <div class="lg:col-span-3">
          <h4 class="footer-heading">{{ $t('ติดต่อเรา') }}</h4>
          <ul class="space-y-3 text-[15px]">
            <li class="flex items-start gap-2.5">
              <span class="material-symbols-rounded icon-20 text-[var(--color-accent-light)] mt-0.5">call</span>
              <a :href="supportPhoneHref()" class="hover:text-white">{{ supportPhone() }}</a>
            </li>
            <li class="flex items-start gap-2.5">
              <i class="fa-brands fa-line text-[18px] text-[var(--color-accent-light)] mt-1 w-5 text-center" aria-hidden="true"></i>
              <a :href="supportLineUrl()" target="_blank" rel="noopener" class="hover:text-white">{{ supportLine() }}</a>
            </li>
            <li class="flex items-start gap-2.5">
              <span class="material-symbols-rounded icon-20 text-[var(--color-accent-light)] mt-0.5">mail</span>
              <a :href="supportEmailHref()" class="hover:text-white break-all">{{ supportEmail() }}</a>
            </li>
            <li v-if="address" class="flex items-start gap-2.5">
              <span class="material-symbols-rounded icon-20 text-[var(--color-accent-light)] mt-0.5">location_on</span>
              <span>{{ address }}</span>
            </li>
          </ul>

          <p class="mt-7 text-[14px] font-semibold text-white">{{ $t('ดาวน์โหลดแอปลุยเลเขา') }}</p>
          <div class="mt-3 flex items-center gap-2">
            <a href="https://apps.apple.com/th/app/luilaykhao/id6770391928?l=th" target="_blank" rel="noopener" :aria-label="$t('ดาวน์โหลดบน App Store')" class="store-badge">
              <i class="fa-brands fa-apple" aria-hidden="true"></i>
              <span class="flex flex-col leading-none"><span class="text-[10px] opacity-75">Download on the</span><span class="text-[15px] font-bold">App Store</span></span>
            </a>
            <a href="https://play.google.com/store/apps/details?id=com.luilaykhao.app&hl=th" target="_blank" rel="noopener" :aria-label="$t('ดาวน์โหลดบน Google Play')" class="store-badge">
              <i class="fa-brands fa-google-play" aria-hidden="true"></i>
              <span class="flex flex-col leading-none"><span class="text-[10px] opacity-75">GET IT ON</span><span class="text-[15px] font-bold">Google Play</span></span>
            </a>
          </div>
        </div>
      </div>

      <!-- ตัวตนตามใบอนุญาต — สิ่งที่แยกผู้ประกอบการจริงออกจากเพจขายทริป
           และเป็นที่แรกที่คนมองหาเวลาจะเช็คว่าเว็บนี้เชื่อได้ไหม -->
      <div class="mt-12 rounded-xl border border-white/10 bg-white/[0.04] p-5 flex flex-col sm:flex-row sm:items-start gap-4">
        <span class="w-10 h-10 rounded-full bg-[var(--color-accent)] text-white flex items-center justify-center shrink-0">
          <span class="material-symbols-rounded icon-20" style="font-variation-settings:'FILL' 1">verified_user</span>
        </span>
        <div class="text-[14px] leading-relaxed space-y-1">
          <p class="font-semibold text-white">
            {{ $t('ใบอนุญาตประกอบธุรกิจนำเที่ยวเลขที่ {licence}', { licence }) }}
            <a v-if="licenceImage" :href="licenceImage" target="_blank" rel="noopener"
              class="font-medium text-[var(--color-accent-light)] hover:underline ml-1">{{ $t('ดูใบอนุญาต') }}</a>
          </p>
          <p>{{ $t('ได้รับอนุญาตให้นำเที่ยวได้ทั้งในประเทศและต่างประเทศ โดยกรมการท่องเที่ยว กระทรวงการท่องเที่ยวและกีฬา') }}</p>
          <p v-if="operator">{{ $t('ผู้ประกอบการ: {operator}', { operator }) }}</p>
        </div>
      </div>
    </div>

    <!-- Bottom Bar -->
    <div class="bg-black/20">
      <div class="max-w-7xl mx-auto px-6 lg:px-8 py-5 flex flex-col md:flex-row items-center justify-between gap-3 text-[13.5px] text-white/60">
        <p>{{ $t('© {v} ลุยเลเขา. สงวนลิขสิทธิ์', { v: new Date().getFullYear() }) }}</p>
        <div class="flex flex-wrap justify-center gap-x-5 gap-y-1.5">
          <router-link to="/terms" class="hover:text-white transition-colors">{{ $t('เงื่อนไขการให้บริการ') }}</router-link>
          <router-link to="/privacy" class="hover:text-white transition-colors">{{ $t('นโยบายความเป็นส่วนตัว') }}</router-link>
          <router-link to="/contact" class="hover:text-white transition-colors">{{ $t('ติดต่อเรา') }}</router-link>
        </div>
      </div>
    </div>
  </footer>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../lib/axios';
import {
  SOCIAL_LINKS,
  operatorAddress, operatorName,
  supportPhone, supportPhoneHref, supportLine, supportLineUrl, supportEmail, supportEmailHref, supportHours,
} from '../lib/contact';
import { licenceImageUrl, licenceNo } from '../lib/licence';

const categories = ref([]);
const loading = ref(true);

const socialLinks = SOCIAL_LINKS;
const licence = licenceNo();
const licenceImage = licenceImageUrl();
// ว่างได้ — แอดมินยังไม่ได้กรอกที่ /admin/settings ก็ซ่อนบรรทัดไป
const operator = operatorName();
const address = operatorAddress();

onMounted(async () => {
  try {
    const res = await api.get('/categories');
    // Show only first 6 popular categories in footer
    categories.value = (res.data.data || []).slice(0, 6);
  } catch (e) {
    console.error('Failed to fetch footer categories:', e);
  } finally {
    loading.value = false;
  }
});
</script>


<style scoped>
.footer-heading {
  margin-bottom: 1.1rem;
  font-size: 17px;
  font-weight: 700;
  color: #fff;
}
.footer-list {
  font-size: 15px;
}
.footer-list li {
  margin-bottom: 0.65rem;
  break-inside: avoid;
}
.footer-list a {
  transition: color 0.2s ease;
}
.footer-list a:hover {
  color: #fff;
}

.footer-social {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 9999px;
  background-color: rgba(255, 255, 255, 0.1);
  color: #fff;
  transition: background-color 0.2s ease;
}
.footer-social i {
  font-size: 15px;
  line-height: 1;
}
.footer-social:hover {
  background-color: var(--color-accent);
}

.footer-cta {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.7rem 1.4rem;
  border-radius: 9999px;
  font-size: 16px;
  font-weight: 700;
  white-space: nowrap;
  transition: background-color 0.2s ease, color 0.2s ease;
}
.footer-cta i {
  font-size: 19px;
  line-height: 1;
}
.footer-cta--light {
  background-color: #fff;
  color: var(--color-primary);
}
.footer-cta--light:hover {
  background-color: var(--color-accent-tint);
}
.footer-cta--line {
  background-color: #06c755;
  color: #fff;
}
.footer-cta--line:hover {
  background-color: #05b34c;
}

.store-badge {
  display: inline-flex;
  white-space: nowrap;
  align-items: center;
  gap: 0.5rem;
  padding: 0.45rem 0.9rem;
  border-radius: 0.6rem;
  border: 1px solid rgba(255, 255, 255, 0.3);
  background-color: #000;
  color: #fff;
  transition: border-color 0.2s ease;
}
.store-badge i {
  font-size: 20px;
  line-height: 1;
}
.store-badge:hover {
  border-color: #fff;
}
</style>
