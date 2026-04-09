<template>
  <div class="about-page font-anuphan bg-[var(--color-sand)]">

    <!-- ══════════════════════════════════════════
         HERO SECTION
    ══════════════════════════════════════════ -->
    <section class="relative min-h-[520px] md:min-h-[700px] flex items-center overflow-hidden -mt-16 bg-[#0D2B1E]">
      <div class="absolute inset-0">
        <img
          src="/images/phusoidao.png"
          alt="ทะเลอันดามัน"
          class="w-full h-full object-cover opacity-80"
        />
        <div class="absolute inset-0 bg-gradient-to-b from-[#0D2B1E]/60 via-[#1A3A2E]/40 to-[#0D2B1E]/90"></div>
      </div>
      <!-- Decorative orbs -->
      <div class="absolute top-20 left-10 w-72 h-72 bg-[var(--color-accent)]/20 rounded-full blur-[100px] pointer-events-none"></div>
      <div class="absolute bottom-16 right-10 w-64 h-64 bg-[var(--color-gold)]/20 rounded-full blur-[80px] pointer-events-none"></div>

      <div class="hero-content relative z-10 w-full h-full flex items-center justify-center px-6 md:px-8 py-24 md:py-32">
        <div class="text-center max-w-4xl mt-10">

          <h1 class="text-5xl sm:text-6xl md:text-7xl lg:text-8xl font-extrabold text-white leading-tight mb-8 drop-shadow-lg">
            ลุยเลเขา
          </h1>
          <p class="text-xl md:text-2xl lg:text-3xl text-white/90 font-medium leading-relaxed max-w-3xl mx-auto drop-shadow-md">
            เพื่อนที่อยากให้คุณออกไปเที่ยว
          </p>
        </div>
      </div>

      <!-- Scroll indicator -->
      <div class="absolute bottom-10 left-1/2 -translate-x-1/2 z-10">
        <div class="w-7 h-12 border-2 border-white/20 rounded-full flex justify-center pt-2 backdrop-blur-sm">
          <div class="w-1.5 h-3 bg-white/70 rounded-full scroll-dot"></div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         OUR STORY SECTION
    ══════════════════════════════════════════ -->
    <section class="py-20 md:py-32 bg-[var(--color-sand)] relative overflow-hidden">
      <!-- Decorative element -->
      <div class="absolute top-0 right-0 w-[800px] h-[800px] bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3 opacity-50"></div>

      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-20 items-center">
          <!-- Text -->
          <div class="lg:col-span-6 order-2 lg:order-1 space-y-8 reveal-section">
            <div class="inline-flex items-center gap-3 text-[var(--color-accent)] font-bold tracking-wider uppercase text-xs">
              <span class="w-10 h-[2px] bg-[var(--color-accent)]"></span>
              จุดเริ่มต้นของเรา
            </div>
            <h2 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-[var(--color-text-dark)] leading-[1.15]">
              เราเริ่มต้นจาก<br class="hidden sm:block" />ความรู้สึกเดียวกัน
            </h2>
            <div class="space-y-6 text-lg text-[var(--color-text-mid)] leading-relaxed">
              <p>
                เราเป็นกลุ่มคนธรรมดาที่ชอบออกไปเที่ยว ชอบทะเล ชอบภูเขา
                และชอบความรู้สึกของการได้ออกไปเจออะไรใหม่ ๆ
              </p>
              <p>
                แต่ทุกครั้งที่อยากจะไปเที่ยว เราต้องกลับเจอเรื่องเดิม ๆ
                ต้องทักแชท รอคำตอบ ไม่รู้ว่าทริปว่างไหม บางครั้งก็พลาดโอกาสดี ๆ
                ทั้งที่อยากไปมาก เราเลยตั้งคำถามว่า
              </p>
              <blockquote class="relative pl-8 py-2 mt-8">
                <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-gradient-to-b from-[var(--color-accent)] to-[var(--color-gold)] rounded-full"></div>
                <p class="text-[var(--color-text-dark)] font-bold text-2xl md:text-3xl italic">
                  "ทำไมการไปเที่ยว มันต้องยากขนาดนี้"
                </p>
              </blockquote>
            </div>
          </div>
          <!-- Image -->
          <div class="lg:col-span-6 order-1 lg:order-2 reveal-section">
            <div class="relative group cursor-default">
              <div class="absolute -inset-4 bg-gradient-to-br from-[var(--color-accent)]/20 to-[var(--color-gold)]/20 rounded-[2.5rem] blur-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-700"></div>
              <img
                src="/images/about.jpg"
                alt="วิวทะเลสวย"
                class="relative rounded-[2rem] shadow-2xl w-full object-cover aspect-[4/5] transition-transform duration-700 group-hover:scale-[1.02]"
              />
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
        <h2 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-[var(--color-text-dark)] leading-tight">
          เพื่อให้การออกไปเที่ยว<br />
          <span class="text-transparent bg-clip-text bg-gradient-to-r from-[var(--color-accent)] to-[#2D5A3D]">"ง่ายขึ้นจริง ๆ"</span>
        </h2>
        <div class="space-y-6 text-lg md:text-xl text-[var(--color-text-mid)] leading-relaxed max-w-3xl mx-auto font-medium">
          <p>
            เราเลยสร้างสิ่งนี้ขึ้นมา เพื่อให้การออกไปเที่ยว "ง่ายขึ้นจริง ๆ"<br class="hidden md:block" />
            ไม่ต้องรอ ไม่ต้องเดา ไม่ต้องลุ้น — แค่อยากไป…ก็ไปได้เลย
          </p>
          <p>
            เราอยากให้ทุกคนรู้สึกแบบเดียวกับเรา ในวันที่ได้ออกไปเจอทะเลสวย ๆ
            หรือเดินป่า สูดอากาศดี ๆ แบบเต็มปอด
            และที่สำคัญ…ไม่ต้องเสียเวลาไปกับความยุ่งยากก่อนจะได้ออกเดินทาง
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
          <div class="bg-white p-12 pt-32 rounded-[2rem] shadow-sm hover:shadow-2xl transition-all duration-500 border border-[var(--color-sand-dark)] hover:border-transparent md:translate-y-8 cursor-default group reveal-section relative mt-24 text-center">
            <div class="absolute -top-32 left-1/2 -translate-x-1/2 z-20 transition-all duration-500 group-hover:scale-110 group-hover:-translate-y-2">
              <img 
                src="/images/abouts_ready.png" 
                alt="ไม่ต้องรอ" 
                class="w-64 h-64 max-w-none object-contain"
              />
            </div>
            <h3 class="text-3xl font-bold text-[var(--color-text-dark)] mb-6">ไม่ต้องรอ</h3>
            <p class="text-lg text-[var(--color-text-mid)] leading-relaxed mx-auto max-w-xs">เช็คได้เลยว่าทริปไหนว่าง จองได้เลยทันที ไม่ต้องรอแอดมินตอบ</p>
          </div>
          
          <!-- Pillar 2 (Featured) -->
          <div class="bg-white p-12 pt-32 rounded-[2rem] shadow-sm hover:shadow-2xl transition-all duration-500 border border-[var(--color-sand-dark)] hover:border-transparent md:translate-y-8 cursor-default group reveal-section relative mt-24 text-center">
            <div class="absolute -top-32 left-1/2 -translate-x-1/2 z-20 transition-all duration-500 group-hover:scale-110 group-hover:-translate-y-2">
              <img 
                src="/images/abouts_buddy.png" 
                alt="เพื่อนคู่เที่ยว" 
                class="w-64 h-64 max-w-none object-contain"
              />
            </div>
            <h3 class="text-3xl font-bold mb-6 text-[var(--color-text-dark)]">เพื่อนคู่เที่ยว</h3>
            <p class="text-[var(--color-text-mid)] text-lg leading-relaxed mx-auto max-w-xs">เราไม่ได้อยากเป็นแค่แพลตฟอร์ม แต่อยากเป็น "เพื่อน" ที่ช่วยให้คุณออกไปเที่ยวได้ง่ายขึ้น</p>
            <div class="mt-10 mx-auto w-14 h-1.5 bg-[var(--color-gold)] rounded-full"></div>
          </div>
          
          <!-- Pillar 3 -->
          <div class="bg-white p-12 pt-32 rounded-[2rem] shadow-sm hover:shadow-2xl transition-all duration-500 border border-[var(--color-sand-dark)] hover:border-transparent md:translate-y-8 cursor-default group reveal-section relative mt-24 text-center">
            <div class="absolute -top-32 left-1/2 -translate-x-1/2 z-20 transition-all duration-500 group-hover:scale-110 group-hover:-translate-y-2">
              <img 
                src="/images/abouts_happy.png" 
                alt="มีความสุขมากขึ้น" 
                class="w-72 h-72 max-w-none object-contain"
              />
            </div>
            <h3 class="text-3xl font-bold text-[var(--color-text-dark)] mb-6">มีความสุขมากขึ้น</h3>
            <p class="text-lg text-[var(--color-text-mid)] leading-relaxed mx-auto max-w-xs">ทุกการเดินทางควรเต็มไปด้วยความสุข ไม่ใช่ความวุ่นวาย</p>
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
                <div class="w-20 h-20 bg-[var(--color-gold)]/20 rounded-2xl flex items-center justify-center mx-auto mb-8 shadow-inner ring-1 ring-white/20">
                  <span class="material-symbols-rounded text-white text-[40px]">explore</span>
                </div>
                <h3 class="text-2xl md:text-3xl font-bold text-white mb-6">เริ่มการเดินทางครั้งใหม่</h3>
                <p class="text-white/60 mb-10 font-medium">ค้นหาทริปที่คุณต้องการได้ทันที และจองได้ง่าย ๆ ในไม่กี่ขั้นตอน</p>
                
                <router-link
                  to="/trips"
                  class="w-full bg-white text-[#0D2B1E] hover:bg-[var(--color-gold)] hover:text-white py-5 rounded-2xl font-black text-xl transition-all duration-500 shadow-xl hover:shadow-[0_20px_40px_rgba(255,255,255,0.15)] flex items-center justify-center gap-3 cursor-pointer group/btn overflow-hidden"
                >
                  <span class="relative z-10">ไปดูทริปกันเลย</span>
                  <span class="material-symbols-rounded relative z-10 text-2xl transform group-hover/btn:translate-x-2 transition-transform duration-500">arrow_right_alt</span>
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
  { icon: 'tour', value: '500+', label: 'ทริปที่ผ่านมา', color: '#0D2B1E' }, // primary
  { icon: 'groups', value: '10k+', label: 'นักเดินทาง', color: '#4CAF7D' }, // accent
  { icon: 'pin_drop', value: '50+', label: 'จุดหมายปลายทาง', color: '#C8963E' }, // gold
  { icon: 'star', value: '4.9', label: 'คะแนนความพึงพอใจ', color: '#0D2B1E' }, // primary
])

const values = ref([
  { icon: 'verified_user', title: 'ความปลอดภัย', desc: 'ทุกทริปผ่านมาตรฐานความปลอดภัยระดับสากล ให้คุณเที่ยวอย่างมั่นใจ', color: '#4CAF7D' },
  { icon: 'eco', title: 'ท่องเที่ยวยั่งยืน', desc: 'เราใส่ใจสิ่งแวดล้อมและสนับสนุนชุมชนท้องถิ่นในทุกกิจกรรม', color: '#66C291' },
  { icon: 'handshake', title: 'จริงใจ โปร่งใส', desc: 'ราคายุติธรรม ไม่มีค่าใช้จ่ายแฝง สิ่งที่เห็นคือสิ่งที่ได้', color: '#C8963E' },
  { icon: 'support_agent', title: 'ดูแลตลอดทริป', desc: 'ทีมงานพร้อมดูแลคุณทุกย่างก้าว ตั้งแต่จองจนกลับถึงบ้าน', color: '#007B8F' },
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
