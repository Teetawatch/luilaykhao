<template>
  <div class="about-page font-anuphan bg-[var(--color-sand)]">

    <!-- ══════════════════════════════════════════
         HERO SECTION
    ══════════════════════════════════════════ -->
    <section class="relative min-h-[520px] md:min-h-[700px] flex items-center overflow-hidden -mt-16 bg-[var(--color-primary)]">
      <div class="absolute inset-0">
        <img
          src="/images/phusoidao.png"
          alt="ลุยเลเขา"
          class="w-full h-full object-cover"
        />
        <!-- Soft color overlay -->
        <div class="absolute inset-0 bg-black/20"></div>
      </div>

      <div class="hero-content relative z-10 w-full h-full flex items-center justify-center px-6 md:px-8 py-24 md:py-32">
        <div class="text-center max-w-5xl mt-10 flex flex-col items-center">
          <div class="w-16 h-1.5 bg-[var(--color-accent)] mb-8 rounded-full shadow-lg"></div>
          <h1 class="text-4xl sm:text-5xl md:text-6xl lg:text-7xl font-black text-white leading-[1.1] mb-8 tracking-tighter drop-shadow-2xl">
            เปลี่ยนความอยากเที่ยว<br />
            <span class="text-[var(--color-accent)]">เป็นการเดินทางที่ง่ายที่สุด</span>
          </h1>
          <p class="text-base md:text-xl lg:text-2xl text-white/90 font-medium leading-relaxed max-w-3xl mx-auto tracking-wide mb-10">
            แพลตฟอร์มที่รวมทริปคุณภาพ จองง่ายเพียงไม่กี่คลิก <br class="hidden md:block"/> พร้อมระบบยืนยันที่ทันใจ เหมือนมีเพื่อนสนิทมาวางแผนให้คุณ
          </p>
          <div class="flex flex-col sm:flex-row items-center gap-4">
            <router-link to="/trips" class="px-10 py-5 bg-[var(--color-accent)] hover:bg-[#3d8b63] text-white rounded-2xl font-black text-xl transition-all duration-300 shadow-xl hover:shadow-[0_20px_40px_rgba(76,175,125,0.3)] hover:-translate-y-1">
              ค้นหาทริปของคุณ
            </router-link>
            <a href="#our-story" class="px-10 py-5 bg-white/10 hover:bg-white/20 backdrop-blur-md text-white border border-white/30 rounded-2xl font-bold text-xl transition-all duration-300 hover:-translate-y-1">
              ทำความรู้จักเรา
            </a>
          </div>
        </div>
      </div>

      <!-- Scroll indicator -->
      <div class="absolute bottom-10 left-1/2 -translate-x-1/2 z-10 opacity-70 hover:opacity-100 transition-opacity">
        <div class="w-8 h-14 border-[3px] border-white/20 rounded-full flex justify-center pt-2 backdrop-blur-sm">
          <div class="w-2 h-3 bg-white rounded-full scroll-dot"></div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         OUR STORY SECTION
    ══════════════════════════════════════════ -->
    <section class="py-20 md:py-32 bg-[var(--color-sand)] relative overflow-hidden">
      <!-- Decorative element -->
      <div class="absolute top-0 right-0 w-[800px] h-[800px] bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3 opacity-50"></div>

      <div id="our-story" class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-20 items-center">
          <!-- Text -->
          <div class="lg:col-span-6 order-2 lg:order-1 space-y-8 reveal-section">
            <div class="inline-flex items-center gap-3 text-[var(--color-accent)] font-bold tracking-wider uppercase text-xs">
              <span class="w-10 h-[2px] bg-[var(--color-accent)]"></span>
              จุดเริ่มต้นของเรา
            </div>
            <h2 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-[var(--color-text-dark)] leading-[1.15]">
              เราเริ่มต้นจาก<br class="hidden sm:block" />ความรักในการเดินทาง
            </h2>
            <div class="space-y-6 text-lg text-[var(--color-text-mid)] leading-relaxed">
              <p>
                เราคือกลุ่มคนที่หลงรักในเสียงคลื่น และตกหลุมรักในสายหมอก 
                เราเชื่อว่าหัวใจของการเดินทาง คือการได้ออกไปเห็นโลกกว้าง 
                สัมผัสอากาศบริสุทธิ์ และเก็บเกี่ยวความทรงจำใหม่ ๆ
              </p>
              <p>
                แต่เราก็รู้ดีว่า บ่อยครั้งที่ "ความอยากไป" ต้องจบลงที่ "ความยุ่งยาก" 
                ทั้งการรอแอดมินตอบ ข้อมูลทริปที่ไม่ชัดเจน หรือการจองที่ซับซ้อน 
                เราจึงตั้งคำถามง่าย ๆ กับตัวเองว่า
              </p>
              <div class="relative py-10 px-8 bg-white/50 rounded-3xl border border-white/80 backdrop-blur-sm shadow-inner group overflow-hidden">
                <div class="absolute -top-4 -left-2 text-[var(--color-accent)]/10 text-9xl font-serif">“</div>
                <p class="relative z-10 text-[var(--color-text-dark)] font-bold text-2xl md:text-3xl italic leading-snug">
                  ทำไมการออกไปพบเจอความสุข <br class="hidden sm:block" />
                  มันถึงต้องเป็นเรื่องยากขนาดนี้?
                </p>
                <div class="absolute -bottom-16 -right-4 text-[var(--color-accent)]/10 text-9xl font-serif">”</div>
              </div>
            </div>
          </div>
          <!-- Image -->
          <div class="lg:col-span-6 order-1 lg:order-2 reveal-section">
            <div class="relative group cursor-default">
              <div class="absolute -inset-4 bg-gradient-to-br from-[var(--color-accent)]/20 to-[var(--color-gold)]/20 rounded-[2.5rem] blur-2xl opacity-50 group-hover:opacity-100 transition-opacity duration-700"></div>
              <img
                src="/images/about5555.jpg"
                alt="บรรยากาศการเดินทางที่เต็มไปด้วยความสุข"
                class="relative rounded-[2rem] shadow-2xl w-full object-cover aspect-[4/5] transition-transform duration-700 group-hover:scale-[1.02]"
              />
              <div class="absolute bottom-6 left-6 right-6 bg-white/90 backdrop-blur-md p-6 rounded-2xl shadow-xl transform translate-y-4 group-hover:translate-y-0 opacity-0 group-hover:opacity-100 transition-all duration-500">
                <p class="text-[var(--color-text-dark)] font-bold text-lg">"ธรรมชาติบำบัดเราได้เสมอ..."</p>
                <p class="text-[var(--color-text-muted)] text-sm italic">— ทีมงานลุยเลเขา</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         WHY WE BUILT THIS
    ══════════════════════════════════════════ -->
    <section class="py-24 md:py-32 bg-white relative">
      <div class="absolute top-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-[var(--color-sand-dark)] to-transparent"></div>
      <div class="absolute bottom-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-[var(--color-sand-dark)] to-transparent"></div>
      
      <div class="max-w-4xl mx-auto px-6 md:px-8 text-center space-y-10 reveal-section">
        <div class="inline-flex items-center gap-3 justify-center text-[var(--color-gold)] font-bold tracking-wider uppercase text-xs">
          <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
          ทำไมเราถึงสร้างสิ่งนี้
          <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
        </div>
        <h2 class="text-3xl md:text-4xl lg:text-5xl font-extrabold text-[var(--color-text-dark)] leading-tight">
          เราสร้างที่นี่ เพื่อให้การออกไปเห็นโลก<br />
          <span class="text-transparent bg-clip-text bg-gradient-to-r from-[var(--color-accent)] to-[#2D5A3D]">"เป็นเรื่องที่ง่ายที่สุด"</span>
        </h2>
        <div class="space-y-6 text-lg md:text-2xl text-[var(--color-text-mid)] leading-relaxed max-w-3xl mx-auto font-medium">
          <p>
            ไม่ต้องรอคำตอบที่เนิ่นนาน ไม่ต้องสับสนกับตารางที่ดูยาก<br class="hidden md:block" />
            แค่คุณเริ่ม "อยากไป" ระบบของเราพร้อมพาคุณ "ไปได้ทันที"
          </p>
          <p class="text-[var(--color-text-muted)] text-base md:text-lg">
            เราดูแลตั้งแต่ระบบหลังบ้านที่ทันสมัย ไปจนถึงการคัดสรรพาร์ทเนอร์ทริปที่ไว้ใจได้
            เพื่อให้ทุกวินาทีที่คุณอยู่กับเรา คือจุดเริ่มต้นของความสุขอย่างแท้จริง
          </p>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         THREE PILLARS
    ══════════════════════════════════════════ -->
    <section class="py-24 md:py-32 bg-[var(--color-sand)] relative">
      <!-- Background pattern -->
      <div class="absolute inset-0 opacity-[0.03]" style="background-image: radial-gradient(var(--color-primary) 1px, transparent 1px); background-size: 24px 24px;"></div>

      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="text-center mb-16 md:mb-20 reveal-section">
          <div class="inline-flex items-center gap-3 justify-center text-[var(--color-gold)] font-bold tracking-wider uppercase text-xs mb-4">
            <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
            หลักการของเรา
            <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
          </div>
          <h2 class="text-3xl md:text-4xl lg:text-5xl font-extrabold text-[var(--color-text-dark)]">สิ่งที่เราตั้งใจทำ</h2>
        </div>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-8 md:gap-10 items-stretch">
          <!-- Pillar 1 -->
          <div class="bg-white p-12 pt-32 rounded-[3rem] shadow-lg hover:shadow-2xl transition-all duration-500 border border-[var(--color-sand-dark)] hover:border-[var(--color-accent)]/30 md:translate-y-8 cursor-default group reveal-section relative mt-24 text-center">
            <div class="absolute -top-32 left-1/2 -translate-x-1/2 z-20 transition-all duration-700 group-hover:scale-110 group-hover:-translate-y-4">
              <img 
                src="/images/abouts_ready.png" 
                alt="ระบบจองอัจฉริยะ" 
                class="w-64 h-64 max-w-none object-contain drop-shadow-2xl"
              />
            </div>
            <h3 class="text-3xl font-bold text-[var(--color-text-dark)] mb-6 group-hover:text-[var(--color-accent)] transition-colors">ไม่ต้องรอแอดมิน</h3>
            <p class="text-lg text-[var(--color-text-mid)] leading-relaxed mx-auto max-w-xs">เช็คที่ว่างแบบ Real-time และจองได้ทันทีตลอด 24 ชั่วโมง สะดวก รวดเร็ว ไม่ต้องรอ</p>
          </div>
          
          <!-- Pillar 2 (Featured) -->
          <div class="bg-white p-12 pt-32 rounded-[3rem] shadow-xl hover:shadow-2xl transition-all duration-500 border-2 border-[var(--color-accent)]/10 hover:border-[var(--color-accent)]/40 md:translate-y-0 cursor-default group reveal-section relative mt-24 text-center">
            <div class="absolute -top-32 left-1/2 -translate-x-1/2 z-20 transition-all duration-700 group-hover:scale-110 group-hover:-translate-y-4">
              <img 
                src="/images/abouts_buddy.png" 
                alt="เพื่อนคู่เที่ยวที่ไว้วางใจ" 
                class="w-64 h-64 max-w-none object-contain drop-shadow-2xl"
              />
            </div>
            <h3 class="text-3xl font-bold mb-6 text-[var(--color-text-dark)] group-hover:text-[var(--color-accent)] transition-colors">เพื่อนร่วมทางตัวจริง</h3>
            <p class="text-[var(--color-text-mid)] text-lg leading-relaxed mx-auto max-w-xs">เราไม่ได้ทำแค่ระบบจอง แต่เราคอยดูแลคุณตั้งแต่ก้าวแรกที่เริ่มแพลน จนก้าวสุดท้ายที่กลับถึงบ้าน</p>
            <div class="mt-10 mx-auto w-14 h-1.5 bg-[var(--color-accent)] rounded-full"></div>
          </div>
          
          <!-- Pillar 3 -->
          <div class="bg-white p-12 pt-32 rounded-[3rem] shadow-lg hover:shadow-2xl transition-all duration-500 border border-[var(--color-sand-dark)] hover:border-[var(--color-accent)]/30 md:translate-y-8 cursor-default group reveal-section relative mt-24 text-center">
            <div class="absolute -top-32 left-1/2 -translate-x-1/2 z-20 transition-all duration-700 group-hover:scale-110 group-hover:-translate-y-4">
              <img 
                src="/images/abouts_happy.png" 
                alt="ความสุขในการเดินทาง" 
                class="w-72 h-72 max-w-none object-contain drop-shadow-2xl"
              />
            </div>
            <h3 class="text-3xl font-bold text-[var(--color-text-dark)] mb-6 group-hover:text-[var(--color-accent)] transition-colors">สุขใจทุกกิโลเมตร</h3>
            <p class="text-lg text-[var(--color-text-mid)] leading-relaxed mx-auto max-w-xs">ทุกทริปถูกคัดสรรมาเพื่อสร้างรอยยิ้ม ให้คุณได้พักผ่อนและชาร์จพลังชีวิตอย่างเต็มที่</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         STATS STRIP
    ══════════════════════════════════════════ -->
    <section class="bg-white py-16 md:py-20 relative">
      <div class="absolute top-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-[var(--color-sand-dark)] to-transparent"></div>
      <div class="absolute bottom-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-[var(--color-sand-dark)] to-transparent"></div>

      <div class="max-w-7xl mx-auto px-6 md:px-8 grid grid-cols-2 md:grid-cols-4 gap-10 md:gap-6 reveal-section">
        <div v-for="stat in stats" :key="stat.label" class="text-center group cursor-default">
          <div class="w-14 h-14 mx-auto mb-4 bg-[var(--color-sand)] rounded-2xl flex items-center justify-center group-hover:scale-110 transition-transform duration-300" :style="`background-color: ${stat.color}15; color: ${stat.color}`">
            <span class="material-symbols-rounded text-3xl">{{ stat.icon }}</span>
          </div>
          <div class="text-4xl md:text-5xl font-extrabold mb-2 tabular-nums" :style="`color: ${stat.color}`">{{ stat.value }}</div>
          <div class="text-[var(--color-text-muted)] font-bold text-xs md:text-sm uppercase tracking-widest">{{ stat.label }}</div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         TEAM / VALUES
    ══════════════════════════════════════════ -->
    <section class="py-24 md:py-32 bg-[var(--color-sand)]">
      <div class="max-w-7xl mx-auto px-6 md:px-8">
        <div class="text-center mb-16 md:mb-20 reveal-section">
          <div class="inline-flex items-center gap-3 justify-center text-[var(--color-accent)] font-bold tracking-wider uppercase text-xs mb-4">
            <span class="w-8 h-[2px] bg-[var(--color-accent)]"></span>
            คุณค่าของเรา
            <span class="w-8 h-[2px] bg-[var(--color-accent)]"></span>
          </div>
          <h2 class="text-3xl md:text-4xl lg:text-5xl font-extrabold text-[var(--color-text-dark)]">สิ่งที่เราเชื่อมั่น</h2>
        </div>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 md:gap-8">
          <div
            v-for="val in values"
            :key="val.icon"
            class="bg-white rounded-[2rem] p-8 text-center border border-[var(--color-sand-dark)] hover:border-transparent hover:shadow-xl transition-all duration-300 transform hover:-translate-y-2 reveal-section group"
          >
            <div class="w-16 h-16 bg-[var(--color-sand)] rounded-2xl flex items-center justify-center mx-auto mb-6 transition-transform duration-300 group-hover:scale-110" :style="`background-color: ${val.color}15; color: ${val.color}`">
              <span class="material-symbols-rounded text-3xl">{{ val.icon }}</span>
            </div>
            <h5 class="font-bold text-xl text-[var(--color-text-dark)] mb-3">{{ val.title }}</h5>
            <p class="text-[var(--color-text-muted)] text-base leading-relaxed">{{ val.desc }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         CLOSING CTA
    ══════════════════════════════════════════ -->
    <!-- ══════════════════════════════════════════
         CLOSING CTA (Full-Width Redesign)
    ══════════════════════════════════════════ -->
    <section class="relative py-32 w-full overflow-hidden bg-[#0D2B1E]">
      <!-- Sophisticated Background Elements -->
      <div class="absolute inset-0 z-0 bg-[url('https://www.transparenttextures.com/patterns/carbon-fibre.png')] opacity-10"></div>
      <div class="absolute top-0 right-0 w-[50rem] h-[50rem] bg-[var(--color-accent)]/10 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/4 pointer-events-none"></div>
      <div class="absolute bottom-0 left-0 w-[40rem] h-[40rem] bg-[var(--color-gold)]/10 rounded-full blur-[120px] translate-y-1/2 -translate-x-1/4 pointer-events-none"></div>
      
      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10 text-center lg:text-left">
        <div class="flex flex-col lg:flex-row items-center justify-between gap-12 lg:gap-24">
          
          <!-- Text Content -->
          <div class="flex-1 max-w-3xl">
            <h2 class="font-anuphan text-4xl md:text-5xl lg:text-7xl font-black text-white mb-8 leading-[1.05] tracking-tight">
              เราไม่ได้อยากเป็นแค่<br />
              <span class="text-[var(--color-gold)]">แพลตฟอร์มจองทริป</span>
            </h2>
            <p class="text-white/70 text-lg md:text-2xl font-medium leading-relaxed mb-0">
              แต่อยากเป็น <span class="text-white font-black underline decoration-[var(--color-gold)] decoration-4 underline-offset-8">"เพื่อน"</span> ที่ช่วยให้คุณออกไปเที่ยวได้ง่ายขึ้น และมีความสุขมากขึ้นในทุกการเดินทางที่คุณเลือกไปกับเรา
            </p>
          </div>

          <!-- Glassmorphism Call to Action -->
          <div class="flex-1 w-full max-w-lg">
            <div class="relative group">
              <!-- Subtle glow background -->
              <div class="absolute -inset-1 bg-gradient-to-r from-[var(--color-accent)] to-[var(--color-gold)] rounded-[2.5rem] blur opacity-20 group-hover:opacity-40 transition duration-1000"></div>
              
              <div class="relative bg-white/5 backdrop-blur-2xl p-10 md:p-14 rounded-[2.5rem] border border-white/10 shadow-3xl text-center">
                <div class="w-20 h-20 bg-[var(--color-accent)]/20 rounded-2xl flex items-center justify-center mx-auto mb-8 shadow-inner ring-1 ring-white/20">
                  <span class="material-symbols-rounded text-white text-[40px]">explore</span>
                </div>
                <h3 class="text-2xl md:text-3xl font-bold text-white mb-6">ค้นหาทริปที่ใช่สำหรับคุณ</h3>
                <p class="text-white/60 mb-10 font-medium">เริ่มต้นความประทับใจครั้งใหม่ได้ทันที <br /> จองง่าย ข้อมูลครบ พร้อมออกเดินทางได้เลย</p>
                
                <router-link
                  to="/trips"
                  class="w-full bg-[var(--color-accent)] text-white hover:bg-[#3d8b63] py-5 rounded-2xl font-black text-xl transition-all duration-500 shadow-xl hover:shadow-[0_20px_40px_rgba(76,175,125,0.3)] flex items-center justify-center gap-3 cursor-pointer group/btn overflow-hidden"
                >
                  <span class="relative z-10">เริ่มการเดินทางครั้งใหม่</span>
                  <span class="material-symbols-rounded relative z-10 text-2xl animate-pulse">hiking</span>
                </router-link>
              </div>
            </div>
          </div>

        </div>
      </div>
    </section>

  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const stats = ref([
  { icon: 'tour', value: '1,240+', label: 'ทริปที่ประทับใจ', color: '#0D2B1E' },
  { icon: 'sentiment_very_satisfied', value: '9,650+', label: 'รอยยิ้มจากนักเดินทาง', color: '#4CAF7D' },
  { icon: 'location_on', value: '45+', label: 'จุดหมายปลายทาง', color: '#C8963E' },
  { icon: 'verified', value: '100%', label: 'ความไว้วางใจ', color: '#0D2B1E' },
])

const values = ref([
  { icon: 'security', title: 'ปลอดภัย 100%', desc: 'ตรวจสอบอุปกรณ์และเส้นทางเดินรถทุกครั้งก่อนออกเดินทาง เพื่อความปลอดภัยสูงสุดของคุณ', color: '#4CAF7D' },
  { icon: 'local_mall', title: 'ราคา Net โปร่งใส', desc: 'ไม่มีค่าใช้จ่ายแอบแฝง สิ่งที่เห็นคือสิ่งที่คุณจ่าย มั่นใจได้ในทุกการทำธุรกรรม', color: '#66C291' },
  { icon: 'volunteer_activism', title: 'ใส่ใจชุมชน', desc: 'เราทำงานร่วมกับคนในท้องถิ่น เพื่อกระจายรายได้และรักษาความงามของธรรมชาติอย่างยั่งยืน', color: '#C8963E' },
  { icon: 'support_agent', title: 'ดูแลแบบ VIP', desc: 'ทีมงานพร้อมให้ความช่วยเหลือตลอดการเดินทาง เพื่อให้คุณอุ่นใจเหมือนมีเพื่อนอยู่ข้างๆ', color: '#007B8F' },
])

onMounted(() => {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return

  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible')
          observer.unobserve(entry.target)
        }
      })
    },
    { threshold: 0.1, rootMargin: "0px 0px -50px 0px" }
  )

  document.querySelectorAll('.reveal-section').forEach((el) => observer.observe(el))
})
</script>

<style scoped>
/* Hero entrance */
.hero-content {
  animation: slideUpFade 1s cubic-bezier(0.16, 1, 0.3, 1) both;
}

@keyframes slideUpFade {
  from { opacity: 0; transform: translateY(40px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* Scroll indicator */
.scroll-dot {
  animation: scrollBounce 1.6s ease-in-out infinite;
}

@keyframes scrollBounce {
  0%, 100% { transform: translateY(0); opacity: 1; }
  50%       { transform: translateY(12px); opacity: 0.3; }
}

/* Reveal on scroll */
.reveal-section {
  opacity: 0;
  transform: translateY(30px);
  transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1), transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
}

.reveal-section.is-visible {
  opacity: 1;
  transform: translateY(0);
}

/* Respect reduced motion */
@media (prefers-reduced-motion: reduce) {
  .hero-content { animation: none; }
  .scroll-dot { animation: none; }
  .reveal-section { opacity: 1; transform: none; transition: none; }
}
</style>
