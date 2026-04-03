<template>
  <div class="problem-page font-anuphan bg-[var(--color-sand)]">

    <!-- ══════════════════════════════════════════
         HERO SECTION
    ══════════════════════════════════════════ -->
    <section class="relative min-h-[520px] md:min-h-[700px] flex items-center overflow-hidden -mt-16 bg-[#0D2B1E]">
      <div class="absolute inset-0">
        <img
          src="/images/about.jpg"
          alt="ปัญหาที่เราพบเจอ"
          class="w-full h-full object-cover opacity-80"
        />
        <div class="absolute inset-0 bg-gradient-to-b from-[#0D2B1E]/60 via-[#1A3A2E]/40 to-[#0D2B1E]/90"></div>
      </div>
      <!-- Decorative orbs -->
      <div class="absolute top-20 left-10 w-72 h-72 bg-[var(--color-accent)]/20 rounded-full blur-[100px] pointer-events-none"></div>
      <div class="absolute bottom-16 right-10 w-64 h-64 bg-[var(--color-gold)]/20 rounded-full blur-[80px] pointer-events-none"></div>

      <div class="hero-content relative z-10 w-full h-full flex items-center justify-center px-6 md:px-8 py-24 md:py-32">
        <div class="text-center max-w-4xl mt-10">
          <h1 class="text-5xl sm:text-6xl md:text-7xl lg:text-7xl font-extrabold text-white leading-tight mb-8 drop-shadow-lg">
            อุปสรรคของการเดินทาง
          </h1>
          <p class="text-xl md:text-2xl lg:text-3xl text-white/90 font-medium leading-relaxed max-w-3xl mx-auto drop-shadow-md">
            สิ่งที่เราอยากแก้ไข เพื่อให้ทุกคนได้ออกไปเที่ยวอย่างแท้จริง
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
         PROBLEMS SECTION
    ══════════════════════════════════════════ -->
    <section class="py-20 md:py-32 bg-[var(--color-sand)] relative overflow-hidden">
      <!-- Decorative element -->
      <div class="absolute top-0 right-0 w-[800px] h-[800px] bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3 opacity-50"></div>

      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="text-center mb-16 md:mb-20 reveal-section">
          <div class="inline-flex items-center gap-3 justify-center text-[var(--color-accent)] font-bold tracking-wider uppercase text-xs mb-4">
            <span class="w-8 h-[2px] bg-[var(--color-accent)]"></span>
            ปัญหาที่พบเจอและอยากแก้ไข
            <span class="w-8 h-[2px] bg-[var(--color-accent)]"></span>
          </div>
          <h2 class="text-3xl md:text-4xl lg:text-5xl font-extrabold text-[var(--color-text-dark)]">ปัญหาที่หลายคนต้องเจอ</h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 md:gap-8">
          <div v-for="(problem, index) in problems" :key="index" class="bg-white rounded-[2rem] p-8 border border-[var(--color-sand-dark)] hover:border-[var(--color-accent)] hover:shadow-xl transition-all duration-300 transform hover:-translate-y-2 reveal-section group">
            <div class="w-16 h-16 bg-[var(--color-sand)] rounded-2xl flex items-center justify-center mb-6 transition-transform duration-300 group-hover:scale-110 shadow-sm" :style="`color: ${problem.color}`">
              <span class="material-symbols-rounded text-3xl">{{ problem.icon }}</span>
            </div>
            <h5 class="font-bold text-xl text-[var(--color-text-dark)] mb-3">{{ problem.title }}</h5>
            <p class="text-[var(--color-text-muted)] text-base leading-relaxed">{{ problem.desc }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         QUOTE SECTION
    ══════════════════════════════════════════ -->
    <section class="py-24 md:py-32 bg-white relative">
      <div class="absolute top-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-[var(--color-sand-dark)] to-transparent"></div>
      
      <div class="max-w-4xl mx-auto px-6 md:px-8 text-center space-y-10 reveal-section">
        <div class="inline-flex items-center gap-3 justify-center text-[var(--color-gold)] font-bold tracking-wider uppercase text-xs">
          <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
          บทสรุปของปัญหา
          <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
        </div>
        <h2 class="text-3xl md:text-4xl lg:text-5xl font-extrabold text-[var(--color-text-dark)] leading-tight">
          <span class="text-transparent bg-clip-text bg-gradient-to-r from-[var(--color-accent)] to-[#2D5A3D]">“ลูกค้าอยากไป…แต่ไปไม่ได้ เพราะระบบยังไม่เอื้อ”</span>
        </h2>
        <div class="pt-6">
          <router-link
            to="/goal"
            class="inline-flex items-center gap-3 bg-[var(--color-accent)] text-white px-10 py-5 rounded-2xl text-lg font-bold hover:bg-[#3D8F66] hover:scale-105 transition-all duration-300 shadow-xl focus:outline-none focus:ring-4 focus:ring-[var(--color-accent)]/30"
          >
            <span class="material-symbols-rounded text-2xl">arrow_forward</span>
            ดูเป้าหมายของเรา
          </router-link>
        </div>
      </div>
    </section>

  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const problems = ref([
  { 
    icon: 'chat', 
    title: 'การสอบถามที่ยุ่งยาก', 
    desc: 'ต้องทัก LINE / Facebook เพื่อสอบถาม', 
    color: '#007B8F' 
  },
  { 
    icon: 'hourglass_empty', 
    title: 'รอการตอบกลับนาน', 
    desc: 'รอแอดมินตอบ (บางครั้งนาน)', 
    color: '#C8963E' 
  },
  { 
    icon: 'event_busy', 
    title: 'สถานะทริปไม่ชัดเจน', 
    desc: 'ไม่รู้ว่าทริปว่างหรือเต็ม', 
    color: '#E53935' 
  },
  { 
    icon: 'category', 
    title: 'ข้อมูลไม่รวมศูนย์', 
    desc: 'ข้อมูลกระจัดกระจาย', 
    color: '#66C291' 
  },
  { 
    icon: 'airline_seat_recline_extra', 
    title: 'ปัญหาการจองที่นั่ง', 
    desc: 'จองที่นั่งได้ตามไม่ต้องการ (จองก่อนได้นั่งข้างหลัง การจองค่อนข้างยุ่งยาก)', 
    color: '#4CAF7D' 
  },
  { 
    icon: 'calendar_today', 
    title: 'ข้อจำกัดเรื่องเวลา', 
    desc: 'อยากไป “วันนี้” แต่หาทริปไม่ได้', 
    color: '#FF9800' 
  },
  { 
    icon: 'directions_bus', 
    title: 'จุดขึ้นรถไม่สะดวก', 
    desc: 'อยู่ต่างจังหวัดแต่ต้องไปขึ้นรถที่กรุงเทพ', 
    color: '#9C27B0' 
  }
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
