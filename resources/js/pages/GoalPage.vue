<template>
  <div class="goal-page font-anuphan bg-[var(--color-sand)]">

    <!-- ══════════════════════════════════════════
         HERO SECTION
    ══════════════════════════════════════════ -->
    <section class="relative min-h-[520px] md:min-h-[700px] flex items-center overflow-hidden -mt-16 bg-[#0D2B1E]">
      <div class="absolute inset-0">
        <img
          src="/images/hiking.png"
          alt="จุดมุ่งหมายของเรา"
          class="w-full h-full object-cover opacity-80"
        />
        <div class="absolute inset-0 bg-gradient-to-b from-[#0D2B1E]/60 via-[#1A3A2E]/40 to-[#0D2B1E]/90"></div>
      </div>
      <!-- Decorative orbs -->
      <div class="absolute top-20 left-10 w-72 h-72 bg-[var(--color-accent)]/20 rounded-full blur-[100px] pointer-events-none"></div>
      <div class="absolute bottom-16 right-10 w-64 h-64 bg-[var(--color-gold)]/20 rounded-full blur-[80px] pointer-events-none"></div>

      <div class="hero-content relative z-10 w-full h-full flex items-center justify-center px-6 md:px-8 py-24 md:py-32">
        <div class="text-center max-w-4xl mt-10">
          <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-white/10 border border-white/20 backdrop-blur-md text-[var(--color-gold)] font-medium tracking-widest uppercase text-xs mb-6">
            จุดมุ่งหมาย
          </div>
          <h1 class="text-5xl sm:text-6xl md:text-7xl lg:text-8xl font-extrabold text-white leading-tight mb-8 drop-shadow-lg">
            เป้าหมายของเรา
          </h1>
          <p class="text-xl md:text-2xl lg:text-3xl text-white/90 font-medium leading-relaxed max-w-3xl mx-auto drop-shadow-md">
            เราไม่ได้เริ่มจากความอยากสร้างธุรกิจใหญ่ แต่เริ่มจากความตั้งใจเล็ก ๆ
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
              เราอยากให้มันง่ายขึ้น
            </div>
            <h2 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-[var(--color-text-dark)] leading-[1.15]">
              เราอยากให้การไปเที่ยว<br class="hidden sm:block" />ง่ายขึ้นสำหรับทุกคน
            </h2>
            <div class="space-y-6 text-lg text-[var(--color-text-mid)] leading-relaxed">
              <p>
                เราไม่ได้เริ่มต้นจากการอยากสร้างธุรกิจใหญ่ แต่เริ่มจากความตั้งใจเล็ก ๆ
                ว่าเราอยากให้การไปเที่ยวของทุกคน "ง่ายขึ้น"
              </p>
              <p>
                เราอยากให้วันที่คุณรู้สึกอยากออกไปทะเล หรืออยากหนีความวุ่นวายไปเดินป่า
                คุณไม่ต้องเสียเวลาไปกับการหาข้อมูลหรือรอใครตอบกลับ
              </p>
              <blockquote class="relative pl-8 py-2 mt-8">
                <div class="absolute left-0 top-0 bottom-0 w-1.5 bg-gradient-to-b from-[var(--color-accent)] to-[var(--color-gold)] rounded-full"></div>
                <p class="text-[var(--color-text-dark)] font-bold text-2xl md:text-3xl italic">
                  "แค่อยากไป…ก็ไปได้เลย"
                </p>
              </blockquote>
            </div>
          </div>
          <!-- Image -->
          <div class="lg:col-span-6 order-1 lg:order-2 reveal-section">
            <div class="relative group cursor-default">
              <div class="absolute -inset-4 bg-gradient-to-br from-[var(--color-accent)]/20 to-[var(--color-gold)]/20 rounded-[2.5rem] blur-2xl opacity-0 group-hover:opacity-100 transition-opacity duration-700"></div>
              <img
                src="https://lh3.googleusercontent.com/aida-public/AB6AXuBag43UzJE9UmkiJb5w7nKzTOsz4KUbtjuw0SMYH-xiHgLkgONAi6tNqN09HSka2oVsWxH-GYLiZEM9SA24MBTgQRB1Z48eIHC4TX5bDG_giZQ4olUTqEXj-Tr-DI_d7LMiUj8s36SyzGOyKmNzEvTyuW5mRUTUW67vD_lqjrWAl6QamFxDjGXxlqg-erCaRQ_fED7JP6wDtc9z-Torw-ze72SkyEaAA9TwW__k37BifdZj6EXchfU0Cg4b9n3CzLbgZ5cpPc_ywP39"
                alt="ออกไปเที่ยวด้วยกัน"
                class="relative rounded-[2rem] shadow-2xl w-full object-cover aspect-[4/5] transition-transform duration-700 group-hover:scale-[1.02]"
              />
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         PART OF YOUR JOURNEY
    ══════════════════════════════════════════ -->
    <section class="py-24 md:py-32 bg-white relative">
      <div class="absolute top-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-[var(--color-sand-dark)] to-transparent"></div>
      <div class="absolute bottom-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-[var(--color-sand-dark)] to-transparent"></div>
      
      <div class="max-w-4xl mx-auto px-6 md:px-8 text-center space-y-10 reveal-section">
        <div class="inline-flex items-center gap-3 justify-center text-[var(--color-gold)] font-bold tracking-wider uppercase text-xs">
          <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
          ส่วนหนึ่งของการเดินทางคุณ
          <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
        </div>
        <h2 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-[var(--color-text-dark)] leading-tight">
          เราอยากเป็น<br />
          <span class="text-transparent bg-clip-text bg-gradient-to-r from-[var(--color-accent)] to-[#2D5A3D]">"ส่วนหนึ่ง"</span> ของการเดินทางคุณ
        </h2>
        <div class="space-y-6 text-lg md:text-xl text-[var(--color-text-mid)] leading-relaxed max-w-3xl mx-auto font-medium">
          <p>
            ไม่ว่าจะเป็นทริปเล็ก ๆ ในวันหยุด หรือช่วงเวลาสำคัญที่คุณอยากพักผ่อนจริง ๆ
          </p>
          <p>
            เราตั้งใจสร้างประสบการณ์ที่ดีในทุกขั้นตอน ตั้งแต่ตอนที่คุณกดจอง ไปจนถึง
            ช่วงเวลาที่คุณกำลังยิ้มอยู่กลางทะเลหรือสูดอากาศดี ๆ บนภูเขา
          </p>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         THREE GOALS
    ══════════════════════════════════════════ -->
    <section class="py-24 md:py-32 bg-[var(--color-sand)] relative">
      <!-- Background pattern -->
      <div class="absolute inset-0 opacity-[0.03]" style="background-image: radial-gradient(var(--color-primary) 1px, transparent 1px); background-size: 24px 24px;"></div>

      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="text-center mb-16 md:mb-20 reveal-section">
          <div class="inline-flex items-center gap-3 justify-center text-[var(--color-gold)] font-bold tracking-wider uppercase text-xs mb-4">
            <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
            เป้าหมายของเรา
            <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
          </div>
          <h2 class="text-3xl md:text-4xl lg:text-5xl font-extrabold text-[var(--color-text-dark)]">สิ่งที่เราตั้งใจมอบให้คุณ</h2>
        </div>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-8 md:gap-10 items-stretch">
          <!-- Goal 1 -->
          <div class="bg-white p-10 rounded-[2rem] shadow-sm hover:shadow-2xl transition-all duration-500 border border-[var(--color-sand-dark)] hover:border-transparent hover:-translate-y-2 cursor-default group reveal-section">
            <div class="w-16 h-16 bg-[var(--color-sand)] rounded-2xl flex items-center justify-center mb-8 group-hover:bg-[var(--color-accent)]/10 group-hover:scale-110 transition-all duration-500">
              <span class="material-symbols-rounded text-[var(--color-accent)] text-3xl">schedule</span>
            </div>
            <h3 class="text-2xl font-bold text-[var(--color-text-dark)] mb-4">ไม่ต้องเสียเวลา</h3>
            <p class="text-lg text-[var(--color-text-mid)] leading-relaxed">ไม่ต้องหาข้อมูล ไม่ต้องรอใครตอบกลับ อยากไปก็จองได้เลยทันที</p>
          </div>
          
          <!-- Goal 2 (Featured) -->
          <div class="bg-gradient-to-br from-[#0D2B1E] to-[var(--color-primary)] text-white p-10 rounded-[2rem] shadow-xl md:-translate-y-6 cursor-default group hover:shadow-2xl transition-all duration-500 reveal-section">
            <div class="w-16 h-16 bg-white/10 rounded-2xl flex items-center justify-center mb-8 group-hover:bg-white/20 group-hover:scale-110 transition-all duration-500 border border-white/10 backdrop-blur-sm">
              <span class="material-symbols-rounded text-[var(--color-accent-light)] text-3xl">directions_walk</span>
            </div>
            <h3 class="text-2xl font-bold mb-4">เป็นส่วนหนึ่งของทริปคุณ</h3>
            <p class="text-white/80 text-lg leading-relaxed">ตั้งแต่กดจองจนถึงช่วงเวลาที่คุณยิ้มอยู่กลางทะเลหรือสูดอากาศดี ๆ บนภูเขา</p>
            <div class="mt-10 w-12 h-1.5 bg-[var(--color-gold)] rounded-full"></div>
          </div>
          
          <!-- Goal 3 -->
          <div class="bg-white p-10 rounded-[2rem] shadow-sm hover:shadow-2xl transition-all duration-500 border border-[var(--color-sand-dark)] hover:border-transparent hover:-translate-y-2 cursor-default group reveal-section">
            <div class="w-16 h-16 bg-[#FFF8EE] rounded-2xl flex items-center justify-center mb-8 group-hover:bg-[var(--color-gold)]/10 group-hover:scale-110 transition-all duration-500">
              <span class="material-symbols-rounded text-[var(--color-gold)] text-3xl">public</span>
            </div>
            <h3 class="text-2xl font-bold text-[var(--color-text-dark)] mb-4">เข้าถึงง่ายสำหรับทุกคน</h3>
            <p class="text-lg text-[var(--color-text-mid)] leading-relaxed">ไม่ยุ่งยาก ไม่ซับซ้อน และไม่ต้องพึ่งโชค ออกแบบให้ทุกคนใช้งานได้จริง</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         GOAL CTA (Full-Width Redesign)
    ══════════════════════════════════════════ -->
    <section class="relative py-32 w-full overflow-hidden bg-[#0D2B1E]">
      <!-- Premium Background Effects -->
      <div class="absolute inset-0 z-0 bg-[url('https://www.transparenttextures.com/patterns/carbon-fibre.png')] opacity-10"></div>
      <div class="absolute top-0 right-0 w-[50rem] h-[50rem] bg-[var(--color-accent)]/10 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/4 pointer-events-none"></div>
      <div class="absolute bottom-0 left-0 w-[40rem] h-[40rem] bg-[var(--color-gold)]/10 rounded-full blur-[120px] translate-y-1/2 -translate-x-1/4 pointer-events-none"></div>
      
      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="flex flex-col lg:flex-row items-center justify-between gap-12 lg:gap-24">
          
          <!-- Text Content -->
          <div class="flex-1 text-center lg:text-left max-w-3xl">
            <h2 class="font-anuphan text-4xl md:text-5xl lg:text-7xl font-black text-white mb-8 leading-[1.05] tracking-tight">
              การท่องเที่ยวที่<br />
              <span class="text-[var(--color-gold)]">"เข้าถึงง่าย"</span> สำหรับทุกคน
            </h2>
            <p class="text-white/70 text-lg md:text-2xl font-medium leading-relaxed mb-0">
              ไม่ยุ่งยาก ไม่ซับซ้อน และไม่ต้องพึ่งโชค — <span class="text-white border-b-2 border-[var(--color-gold)] italic">เราออกแบบทุกอย่าง</span> ให้เหมือนกับคุณมีเพื่อนที่คอยเดินเคียงข้างในทุกเส้นทาง
            </p>
          </div>

          <!-- Glassmorphism Action Card -->
          <div class="flex-1 w-full max-w-lg">
            <div class="relative group">
              <div class="absolute -inset-1 bg-gradient-to-r from-[var(--color-accent)] to-[var(--color-gold)] rounded-[2.5rem] blur opacity-20 group-hover:opacity-40 transition duration-1000"></div>
              
              <div class="relative bg-white/5 backdrop-blur-2xl p-10 md:p-14 rounded-[2.5rem] border border-white/10 shadow-3xl text-center">
                <div class="w-20 h-20 bg-[var(--color-accent)]/20 rounded-2xl flex items-center justify-center mx-auto mb-8 shadow-inner ring-1 ring-white/20">
                  <span class="material-symbols-rounded text-white text-[40px]">auto_awesome</span>
                </div>
                <h3 class="text-2xl md:text-3xl font-bold text-white mb-6">เริ่มสร้างประสบการณ์ใหม่</h3>
                <p class="text-white/60 mb-10 font-medium">ให้เราเป็นส่วนหนึ่งในการเดินทางครั้งถัดไปของคุณ สัมผัสความง่ายในแบบที่ไม่เคยมีมาก่อน</p>
                
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

    <!-- ══════════════════════════════════════════
         LONG-TERM VISION
    ══════════════════════════════════════════ -->
    <section class="py-24 md:py-32 bg-white relative">
      <div class="absolute top-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-[var(--color-sand-dark)] to-transparent"></div>
      
      <div class="max-w-7xl mx-auto px-6 md:px-8">
        <div class="text-center mb-16 md:mb-20 reveal-section">
          <div class="inline-flex items-center gap-3 justify-center text-[var(--color-accent)] font-bold tracking-wider uppercase text-xs mb-4">
            <span class="w-8 h-[2px] bg-[var(--color-accent)]"></span>
            วิสัยทัศน์ระยะยาว
            <span class="w-8 h-[2px] bg-[var(--color-accent)]"></span>
          </div>
          <h2 class="text-3xl md:text-4xl lg:text-5xl font-extrabold text-[var(--color-text-dark)]">สิ่งที่เราอยากเห็น</h2>
        </div>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 md:gap-8">
          <div
            v-for="vision in visions"
            :key="vision.icon"
            class="bg-[var(--color-sand)]/50 rounded-[2rem] p-8 text-center border border-[var(--color-sand-dark)] hover:border-[var(--color-accent)] hover:shadow-xl transition-all duration-300 transform hover:-translate-y-2 reveal-section group"
          >
            <div class="w-16 h-16 bg-white rounded-2xl flex items-center justify-center mx-auto mb-6 transition-transform duration-300 group-hover:scale-110 shadow-sm" :style="`color: ${vision.color}`">
              <span class="material-symbols-rounded text-3xl">{{ vision.icon }}</span>
            </div>
            <h5 class="font-bold text-xl text-[var(--color-text-dark)] mb-3">{{ vision.title }}</h5>
            <p class="text-[var(--color-text-muted)] text-base leading-relaxed">{{ vision.desc }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         CLOSING CTA
    ══════════════════════════════════════════ -->
    <section class="py-20 md:py-32 px-6 md:px-8 bg-[var(--color-sand)]">
      <div class="relative rounded-[3rem] overflow-hidden max-w-7xl mx-auto reveal-section shadow-2xl">
        <div class="absolute inset-0">
          <img
            src="https://lh3.googleusercontent.com/aida-public/AB6AXuCmCKDLEGoOVQFw1kaPZN7DOFei4Sh2AuqBeGmnF3Km7RNvNgFDQ8o_eSEkgIR8J-JesfXKwY-sMDDt5zWcGg262wJt1lCJAMTaWA9RYnBb59T-vfGPo8j6TFSYKSmSwcVFpeHIlGaO8BNL3d8XU5NWsMJKxVgbIn9C6wko1DGIgjnrtRrIPHa162wC8nOlu53ULHWXy9YxgA1zj_jbXKw0bB5itdpH0WQyKflXCiuNByGNXlmK3DoV7RZ5BuOTe7DdNoz2lb3hfb-U"
            alt=""
            class="w-full h-full object-cover"
          />
          <div class="absolute inset-0 bg-gradient-to-br from-[#0D2B1E]/95 via-[#1A3A2E]/90 to-[#2D5A3D]/80"></div>
        </div>
        
        <!-- Decorative -->
        <div class="absolute top-0 right-0 w-96 h-96 bg-[var(--color-accent-light)]/10 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2 pointer-events-none"></div>
        <div class="absolute bottom-0 left-10 w-64 h-64 bg-[var(--color-gold)]/15 rounded-full blur-3xl translate-y-1/2 pointer-events-none"></div>

        <div class="relative z-10 p-12 md:p-24 lg:p-28 text-center text-white space-y-8 flex flex-col items-center">
          <div class="inline-flex items-center gap-3 justify-center text-[var(--color-gold)] font-bold tracking-wider uppercase text-xs">
            <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
            วิสัยทัศน์ในอนาคต
            <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
          </div>
          <h2 class="text-4xl md:text-5xl lg:text-6xl font-extrabold leading-tight max-w-4xl">
            เราอยากเห็นการท่องเที่ยวที่ "เข้าถึงง่าย" สำหรับทุกคน
          </h2>
          <p class="text-lg md:text-xl lg:text-2xl text-white/80 leading-relaxed max-w-3xl font-medium">
            ไม่ยุ่งยาก ไม่ซับซ้อน และไม่ต้องพึ่งโชค —
            <span class="text-[var(--color-gold)]">ออกแบบให้เหมือนกับคุณมีเพื่อนที่คอยช่วยทุกอย่าง</span>
          </p>
          <div class="pt-6">
            <router-link
              to="/trips"
              class="inline-flex items-center gap-3 bg-[var(--color-accent)] text-white px-10 py-5 rounded-2xl text-lg font-bold hover:bg-[#3D8F66] hover:scale-105 transition-all duration-300 shadow-xl focus:outline-none focus:ring-4 focus:ring-[var(--color-accent)]/30"
            >
              <span class="material-symbols-rounded text-2xl">explore</span>
              ไปดูทริปกันเลย
            </router-link>
          </div>
        </div>
      </div>
    </section>

  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const visions = ref([
  { icon: 'lock_open', title: 'เข้าถึงง่าย', desc: 'ทุกคนสามารถเข้าถึงการท่องเที่ยวที่ดีได้โดยไม่มีอุปสรรคใด ๆ', color: '#4CAF7D' },
  { icon: 'remove_road', title: 'ไม่ยุ่งยาก', desc: 'กระบวนการจองที่เรียบง่าย ตรงไปตรงมา ทำได้ทุกที่ทุกเวลา', color: '#66C291' },
  { icon: 'workspace_premium', title: 'ไม่ซับซ้อน', desc: 'ข้อมูลครบ ชัดเจน ไม่มีค่าใช้จ่ายแฝง สิ่งที่เห็นคือสิ่งที่ได้', color: '#C8963E' },
  { icon: 'favorite', title: 'ไม่ต้องพึ่งโชค', desc: 'ออกแบบให้ทุกอย่างมีระบบ เชื่อถือได้ มั่นใจได้ทุกครั้ง', color: '#007B8F' },
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
