<template>
  <footer class="bg-white text-text-dark mt-auto border-t border-sand-dark/40 relative overflow-hidden">
    <!-- Subtle background decoration -->
    <div class="absolute top-0 right-0 -mr-20 -mt-20 w-64 h-64 rounded-full bg-sand/30 blur-3xl -z-10 pointer-events-none"></div>

    <div class="max-w-7xl mx-auto px-6 py-16 relative z-10">
      <div class="grid grid-cols-1 md:grid-cols-12 gap-12 md:gap-8 lg:gap-12">
        
        <!-- Brand & About -->
        <!-- Brand & About -->
        <div class="md:col-span-6 lg:col-span-4">
          <div class="flex items-center gap-3 mb-6">
            <img src="/images/logo.png" alt="ลุยเลเขา Logo" class="w-24 h-24 object-contain drop-shadow-sm" />
          </div>
          <p class="text-m text-text-muted leading-relaxed max-w-sm mb-6">
            เราไม่ได้อยากเป็นแค่แพลตฟอร์มจองทริป แต่อยากเป็น "เพื่อน" ที่ช่วยให้คุณออกไปเที่ยวได้ง่ายขึ้น และมีความสุขมากขึ้นในทุกการเดินทาง
          </p>
          
          <!-- Social Icons -->
          <div class="flex items-center gap-3">
            <a href="#" class="w-9 h-9 rounded-full border border-sand-dark/60 flex items-center justify-center text-text-muted hover:bg-accent hover:border-accent hover:text-white transition-all duration-300">
              <i class="fa-brands fa-facebook-f"></i>
            </a>
            <a href="#" class="w-9 h-9 rounded-full border border-sand-dark/60 flex items-center justify-center text-text-muted hover:bg-accent hover:border-accent hover:text-white transition-all duration-300">
              <i class="fa-brands fa-instagram"></i>
            </a>
            <a href="#" class="w-9 h-9 rounded-full border border-sand-dark/60 flex items-center justify-center text-text-muted hover:bg-accent hover:border-accent hover:text-white transition-all duration-300">
              <i class="fa-brands fa-line text-lg"></i>
            </a>
          </div>
        </div>

        <!-- Activities -->
        <div class="md:col-span-3 lg:col-span-2 lg:pl-4">
          <h4 class="font-anuphan font-bold text-lg mb-6 text-text-dark">
            กิจกรรมยอดนิยม
          </h4>
          <ul class="space-y-3 text-sm text-text-muted">
            <li>
              <router-link to="/trips?type=trekking" class="group flex items-center gap-3 hover:text-accent transition-colors">
                <span class="w-8 h-8 rounded-lg bg-sand/50 flex items-center justify-center group-hover:bg-accent/10 group-hover:text-accent transition-colors">
                  <span class="material-symbols-rounded text-[18px]">hiking</span>
                </span>
                <span class="font-medium">เดินป่า</span>
              </router-link>
            </li>
            <li>
              <router-link to="/trips?type=snorkeling" class="group flex items-center gap-3 hover:text-accent transition-colors">
                <span class="w-8 h-8 rounded-lg bg-sand/50 flex items-center justify-center group-hover:bg-accent/10 group-hover:text-accent transition-colors">
                  <span class="material-symbols-rounded text-[18px]">scuba_diving</span>
                </span>
                <span class="font-medium">ดำน้ำตื้น</span>
              </router-link>
            </li>
            <li>
              <router-link to="/trips?type=climbing" class="group flex items-center gap-3 hover:text-accent transition-colors">
                <span class="w-8 h-8 rounded-lg bg-sand/50 flex items-center justify-center group-hover:bg-accent/10 group-hover:text-accent transition-colors">
                  <span class="material-symbols-rounded text-[18px]">landscape</span>
                </span>
                <span class="font-medium">ปีนผา</span>
              </router-link>
            </li>
          </ul>
        </div>

        <!-- Contact -->
        <div class="md:col-span-3 lg:col-span-3">
          <h4 class="font-anuphan font-bold text-lg mb-6 text-text-dark">
            ติดต่อเรา
          </h4>
          <ul class="space-y-4 text-sm text-text-muted">
            <li class="flex items-start gap-3.5 group cursor-default">
              <div class="w-8 h-8 rounded-full bg-accent/10 flex items-center justify-center text-accent shrink-0 group-hover:bg-accent group-hover:text-white transition-colors">
                <i class="fa-brands fa-line text-lg"></i>
              </div>
              <div class="pt-1.5">
                <p class="font-semibold text-text-dark leading-none mb-1">LINE Official</p>
                <p>@luilaykhao</p>
              </div>
            </li>
            <li class="flex items-start gap-3.5 group cursor-default">
              <div class="w-8 h-8 rounded-full bg-accent/10 flex items-center justify-center text-accent shrink-0 group-hover:bg-accent group-hover:text-white transition-colors">
                <span class="material-symbols-rounded text-[18px]">call</span>
              </div>
              <div class="pt-1.5">
                <p class="font-semibold text-text-dark leading-none mb-1">โทรศัพท์</p>
                <p>062-612-6006</p>
              </div>
            </li>
            <li class="flex items-start gap-3.5 group cursor-default">
              <div class="w-8 h-8 rounded-full bg-accent/10 flex items-center justify-center text-accent shrink-0 group-hover:bg-accent group-hover:text-white transition-colors">
                <span class="material-symbols-rounded text-[18px]">mail</span>
              </div>
              <div class="pt-1.5">
                <p class="font-semibold text-text-dark leading-none mb-1">อีเมล</p>
                <p>luilaykhao.info@gmail.com</p>
              </div>
            </li>
          </ul>
        </div>

        <!-- Download App -->
        <div class="md:col-span-12 lg:col-span-3 lg:pl-4">
          <h4 class="font-anuphan font-bold text-lg mb-6 text-text-dark">
            ดาวน์โหลด Luilaykhao
          </h4>
          <div class="flex items-center gap-3 ">
            <div class="shrink-0 -mt-10">
              <img src="/images/logo.png" alt="Luilaykhao Logo" class="w-20 h-20 rounded-[1.25rem] shadow-sm border border-gray-100 bg-white p-2 object-contain" />
            </div>
            <div class="flex flex-col gap-0 -mt-10">
              <div class="block">
                <img src="/images/icon-appstore.png" alt="App Store" class="w-28 h-28 object-contain" />
              </div>
              <div class="block -mt-15">
                <img src="/images/icon-playstore.png" alt="Play Store" class="w-28 h-28 object-contain" />
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Bottom Bar -->
      <div class="border-t border-sand-dark/40 mt-16 pt-8 flex flex-col md:flex-row items-center justify-between gap-4">
        <div class="text-sm text-text-muted font-medium">
          &copy; {{ new Date().getFullYear() }} ลุยเลเขา. All rights reserved.
        </div>
        <div class="flex gap-6 text-sm font-medium text-text-muted">
          <router-link to="/terms" class="hover:text-accent transition-colors">เงื่อนไขการให้บริการ</router-link>
          <router-link to="/privacy" class="hover:text-accent transition-colors">นโยบายความเป็นส่วนตัว</router-link>
        </div>
      </div>
    </div>
  </footer>
</template>
