<template>
  <div class="goal-page font-anuphan bg-[var(--color-sand)]">

    <!-- ══════════════════════════════════════════
         HERO SECTION
    ══════════════════════════════════════════ -->
    <section class="relative min-h-[600px] md:min-h-[800px] flex items-center overflow-hidden -mt-16 bg-[var(--color-primary)]">
      <div class="absolute inset-0">
        <img
          src="/images/hiking.webp"
          alt="ทำให้การท่องเที่ยวธรรมชาติ เป็นเรื่องง่ายสำหรับทุกคน"
          class="w-full h-full object-cover scale-110 motion-safe:animate-[subtle-zoom_20s_infinite_alternate]"
        />
        <!-- Deep color overlay for better text readability -->
        <div class="absolute inset-0 bg-gradient-to-b from-black/40 via-black/20 to-[var(--color-sand)]/20"></div>
      </div>

      <div class="hero-content relative z-10 w-full h-full flex items-center justify-center px-6 md:px-8 py-24 md:py-32">
        <div class="text-center max-w-5xl mt-10 flex flex-col items-center">
          <div class="w-20 h-1.5 bg-[var(--color-accent)] mb-8 rounded-full shadow-lg"></div>
          
          <h1 class="text-5xl sm:text-6xl md:text-7xl lg:text-8xl font-black text-white leading-[1.1] mb-8 tracking-tight drop-shadow-xl">
            ทำให้การท่องเที่ยวธรรมชาติ<br/>
            <span class="text-[var(--color-accent-light)]">เป็นเรื่องง่าย</span> สำหรับทุกคน
          </h1>
          
          <p class="text-xl md:text-2xl lg:text-3xl text-white/90 font-medium leading-relaxed max-w-3xl mx-auto tracking-wide mb-12 drop-shadow-md">
            เพราะเราเชื่อว่าการผจญภัยที่ยอดเยี่ยม ไม่ควรถูกขัดจังหวะด้วยความยุ่งยากในการจองและข้อมูลที่สับสน
          </p>

          <!-- Trust Signals -->
          <div class="flex flex-wrap justify-center gap-8 md:gap-16 pt-8 border-t border-white/20 w-full max-w-2xl">
            <div class="flex flex-col items-center">
              <span class="text-3xl md:text-4xl font-black text-white">10,000+</span>
              <span class="text-sm md:text-base text-white/70 font-bold uppercase tracking-widest">นักเดินทางที่ไว้วางใจ</span>
            </div>
            <div class="flex flex-col items-center">
              <div class="flex items-center gap-1 mb-1">
                <span class="text-3xl md:text-4xl font-black text-white">4.8</span>
                <span class="material-symbols-rounded text-[var(--color-gold)] text-2xl md:text-3xl">star</span>
              </div>
              <span class="text-sm md:text-base text-white/70 font-bold uppercase tracking-widest">คะแนนรีวิวจากผู้ใช้จริง</span>
            </div>
            <div class="flex flex-col items-center">
              <span class="text-3xl md:text-4xl font-black text-white">24/7</span>
              <span class="text-sm md:text-base text-white/70 font-bold uppercase tracking-widest">ทีมงานดูแลตลอดทริป</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Scroll indicator -->
      <div class="absolute bottom-10 left-1/2 -translate-x-1/2 z-10 opacity-70 hover:opacity-100 transition-opacity">
        <div class="w-8 h-14 border-[3px] border-white/30 rounded-full flex justify-center pt-2 backdrop-blur-sm">
          <div class="w-2 h-3 bg-white rounded-full scroll-dot"></div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         OUR STORY SECTION
    ══════════════════════════════════════════ -->
    <section class="py-24 md:py-40 bg-[var(--color-sand)] relative overflow-hidden">
      <!-- Decorative element -->
      <div class="absolute top-0 right-0 w-[800px] h-[800px] bg-white rounded-full blur-3xl -translate-y-1/2 translate-x-1/3 opacity-50"></div>
      <div class="absolute bottom-0 left-0 w-64 h-64 bg-[var(--color-accent)]/5 rounded-full blur-3xl translate-y-1/2 -translate-x-1/2"></div>

      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-24 items-center">
          <!-- Text -->
          <div class="lg:col-span-7 order-2 lg:order-1 space-y-10 reveal-section">
            <div class="inline-flex items-center gap-3 text-[var(--color-accent)] font-bold tracking-wider uppercase text-xs">
              <span class="w-10 h-[2px] bg-[var(--color-accent)]"></span>
              จุดเริ่มต้นของเรา
            </div>
            <h2 class="text-4xl md:text-5xl lg:text-7xl font-extrabold text-[var(--color-text-dark)] leading-[1.1]">
              จากความเจ็บปวด<br class="hidden sm:block" />สู่ความตั้งใจที่จะเปลี่ยน
            </h2>
            <div class="space-y-8 text-xl text-[var(--color-text-mid)] leading-relaxed font-medium">
              <p>
                เราไม่ได้เริ่มต้นจากการอยากสร้างธุรกิจใหญ่ แต่เริ่มจาก "ความหงุดหงิด" ที่เราเจอในฐานะนักเดินทางเหมือนกับคุณ
              </p>
              <p>
                เราเคยติดอยู่กับการหาข้อมูลทริปที่กระจัดกระจาย การจองที่ต้องรอแอดมินตอบเป็นวัน ๆ และความรู้สึกไม่มั่นใจว่าทริปที่จองไปจะเป็นอย่างไร
              </p>
              <p>
                ลุยเลยเขา (Luilaykhao) จึงถูกสร้างขึ้นมาเพื่อให้คุณไม่ต้องมองหา 'โชค' ในการไปเที่ยว แต่ให้คุณสามารถวางแผนและออกเดินทางได้ทันทีที่คุณพร้อม
              </p>
              
              <blockquote class="relative pl-10 py-6 mt-12 bg-white/40 backdrop-blur-sm rounded-3xl border-l-8 border-[var(--color-accent)] pr-8 shadow-sm">
                <p class="text-[var(--color-text-dark)] font-bold text-2xl md:text-3xl italic leading-snug">
                  "เพราะช่วงเวลาที่ดีที่สุดในการออกไปเห็นโลก คือตอนที่คุณ 'อยากไป' ที่สุด"
                </p>
              </blockquote>
            </div>
          </div>
          <!-- Image -->
          <div class="lg:col-span-5 order-1 lg:order-2 reveal-section">
            <div class="relative group">
              <div class="absolute -inset-6 bg-gradient-to-br from-[var(--color-accent)]/20 to-[var(--color-gold)]/20 rounded-[3rem] blur-3xl opacity-0 group-hover:opacity-100 transition-opacity duration-700"></div>
              <div class="relative rounded-[2.5rem] overflow-hidden shadow-2xl aspect-[4/5]">
                <img
                  src="/images/nature_travel.webp"
                  alt="ความสุขของการเดินทางธรรมชาติ"
                  class="w-full h-full object-cover transition-transform duration-1000 group-hover:scale-110"
                />
                <div class="absolute inset-0 bg-gradient-to-t from-black/40 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
              </div>
              
              <!-- Floating Badge -->
              <div class="absolute -bottom-6 -left-6 bg-white p-6 rounded-3xl shadow-xl z-20 hidden md:block border border-[var(--color-sand-dark)] animate-bounce-subtle">
                <div class="flex items-center gap-4">
                  <div class="w-12 h-12 bg-[var(--color-accent)]/10 rounded-2xl flex items-center justify-center">
                    <span class="material-symbols-rounded text-[var(--color-accent)] text-3xl">favorite</span>
                  </div>
                  <div>
                    <div class="text-sm text-[var(--color-text-mid)] font-bold uppercase">Travel with Heart</div>
                    <div class="text-lg text-[var(--color-text-dark)] font-black">ใส่ใจทุกย่างก้าว</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         PART OF YOUR JOURNEY (Supporting Section)
    ══════════════════════════════════════════ -->
    <section class="py-32 md:py-48 bg-white relative overflow-hidden">
      <div class="absolute top-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-[var(--color-sand-dark)] to-transparent"></div>
      
      <div class="max-w-6xl mx-auto px-6 md:px-8 relative z-10 text-center">
        <div class="inline-flex items-center gap-3 justify-center text-[var(--color-gold)] font-bold tracking-wider uppercase text-xs mb-8">
          <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
          ไม่ใช่แค่แพลตฟอร์ม แต่คือเพื่อนร่วมทาง
          <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
        </div>
        
        <h2 class="text-4xl md:text-5xl lg:text-7xl font-extrabold text-[var(--color-text-dark)] leading-tight mb-12">
          เราอยากเป็น <span class="text-[var(--color-accent)]">"ส่วนหนึ่ง"</span><br />ที่ช่วยให้คุณออกไปเที่ยวได้ง่ายขึ้น
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-12 text-left mt-20">
          <div class="space-y-4 reveal-section">
            <div class="w-14 h-14 bg-[var(--color-accent)]/10 rounded-2xl flex items-center justify-center mb-6">
              <span class="material-symbols-rounded text-[var(--color-accent)] text-3xl">map</span>
            </div>
            <h3 class="text-2xl font-bold text-[var(--color-text-dark)]">วางแผนให้เสร็จในที่เดียว</h3>
            <p class="text-lg text-[var(--color-text-mid)] leading-relaxed">
              ไม่ต้องเปิดหาข้อมูลหลายเว็บหรือรอแอดมินตอบเป็นชั่วโมง เราคัดกรองข้อมูลทริป เส้นทาง และราคาที่โปร่งใสมาให้คุณเลือกได้ทันที
            </p>
          </div>
          <div class="space-y-4 reveal-section">
            <div class="w-14 h-14 bg-[var(--color-gold)]/10 rounded-2xl flex items-center justify-center mb-6">
              <span class="material-symbols-rounded text-[var(--color-gold)] text-3xl">support_agent</span>
            </div>
            <h3 class="text-2xl font-bold text-[var(--color-text-dark)]">อุ่นใจเหมือนมีเพื่อนไปด้วย</h3>
            <p class="text-lg text-[var(--color-text-mid)] leading-relaxed">
              ไม่ว่าจะเป็นทริปเดินป่าครั้งแรก หรือการเดินทางไปยังจุดหมายที่ไม่คุ้นเคย ทีมงานและพาร์ทเนอร์ของเราจะคอยดูแลคุณตั้งแต่ก้าวแรก
            </p>
          </div>
        </div>
      </div>
      
      <!-- Decorative pattern -->
      <div class="absolute -bottom-24 -right-24 w-96 h-96 bg-[var(--color-sand)] rounded-full opacity-50 blur-3xl"></div>
    </section>

    <!-- ══════════════════════════════════════════
         VALUE CARDS (Three Goals)
    ══════════════════════════════════════════ -->
    <section class="py-32 md:py-48 bg-[var(--color-sand)] relative">
      <div class="absolute inset-0 opacity-[0.03]" style="background-image: radial-gradient(var(--color-primary) 1px, transparent 1px); background-size: 32px 32px;"></div>

      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="text-center mb-24 reveal-section">
          <div class="inline-flex items-center gap-3 justify-center text-[var(--color-accent)] font-bold tracking-wider uppercase text-xs mb-4">
            <span class="w-8 h-[2px] bg-[var(--color-accent)]"></span>
            ค่านิยมที่เรายึดถือ
            <span class="w-8 h-[2px] bg-[var(--color-accent)]"></span>
          </div>
          <h2 class="text-4xl md:text-5xl lg:text-6xl font-extrabold text-[var(--color-text-dark)]">สิ่งที่เราตั้งใจมอบให้คุณ</h2>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-12 items-stretch">
          <!-- Card 1 -->
          <div class="bg-white p-10 pt-36 rounded-[3.5rem] shadow-lg hover:shadow-2xl transition-all duration-700 border border-white hover:border-[var(--color-accent)]/20 cursor-default group reveal-section relative mt-24 text-center overflow-hidden">
             <!-- Card background decoration -->
            <div class="absolute top-0 right-0 w-32 h-32 bg-[var(--color-accent)]/5 rounded-full blur-2xl -translate-y-1/2 translate-x-1/2"></div>
            
            <div class="absolute -top-32 left-1/2 -translate-x-1/2 z-20 transition-all duration-700 group-hover:scale-110 group-hover:-translate-y-6">
              <img 
                src="/images/fast_nowaitng.webp" 
                alt="จองทริปได้ในไม่กี่คลิก" 
                class="w-72 h-72 max-w-none object-contain drop-shadow-2xl"
              />
            </div>
            <h3 class="text-2xl font-black text-[var(--color-text-dark)] mb-6 group-hover:text-[var(--color-accent)] transition-colors">จองทริปได้ในไม่กี่คลิก</h3>
            <p class="text-lg text-[var(--color-text-mid)] leading-relaxed font-medium">ไม่ต้องเสียเวลาหาข้อมูล ไม่ต้องรอแอดมินตอบกลับ อยากไปวันไหนระบบเราจัดให้ทันที</p>
          </div>
          
          <!-- Card 2 (Featured) -->
          <div class="bg-white p-10 pt-36 rounded-[3.5rem] shadow-xl hover:shadow-2xl transition-all duration-700 border-2 border-[var(--color-accent)]/10 hover:border-[var(--color-accent)]/40 cursor-default group reveal-section relative mt-24 text-center overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-b from-[var(--color-accent)]/5 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
            
            <div class="absolute -top-32 left-1/2 -translate-x-1/2 z-20 transition-all duration-700 group-hover:scale-110 group-hover:-translate-y-6">
              <img 
                src="/images/feel_good.webp" 
                alt="ดูแลคุณตลอดเส้นทาง" 
                class="w-72 h-72 max-w-none object-contain drop-shadow-2xl"
              />
            </div>
            <h3 class="text-2xl font-black mb-6 text-[var(--color-text-dark)] group-hover:text-[var(--color-accent)] transition-colors">ดูแลคุณตลอดเส้นทาง</h3>
            <p class="text-[var(--color-text-mid)] text-lg leading-relaxed font-medium">ไม่ใช่แค่การจอง แต่เราคือเพื่อนร่วมทางที่พร้อมช่วยเหลือคุณจนถึงจุดหมายอย่างปลอดภัย</p>
            <div class="mt-8 mx-auto w-16 h-1.5 bg-[var(--color-gold)] rounded-full"></div>
          </div>
          
          <!-- Card 3 -->
          <div class="bg-white p-10 pt-36 rounded-[3.5rem] shadow-lg hover:shadow-2xl transition-all duration-700 border border-white hover:border-[var(--color-accent)]/20 cursor-default group reveal-section relative mt-24 text-center overflow-hidden">
            <div class="absolute bottom-0 left-0 w-32 h-32 bg-[var(--color-gold)]/5 rounded-full blur-2xl translate-y-1/2 -translate-x-1/2"></div>
            
            <div class="absolute -top-32 left-1/2 -translate-x-1/2 z-20 transition-all duration-700 group-hover:scale-110 group-hover:-translate-y-6">
              <img 
                src="/images/no_wating.webp" 
                alt="เริ่มผจญภัยได้ทุกคน" 
                class="w-72 h-72 max-w-none object-contain drop-shadow-2xl"
              />
            </div>
            <h3 class="text-2xl font-black text-[var(--color-text-dark)] mb-6 group-hover:text-[var(--color-accent)] transition-colors">เริ่มผจญภัยได้ทุกคน</h3>
            <p class="text-lg text-[var(--color-text-mid)] leading-relaxed font-medium">มีแผนการเดินทางที่ออกแบบมาให้เหมาะสม ตั้งแต่นักเดินป่ามือใหม่ไปจนถึงสายลุยระดับโปร</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         GOAL CTA (Action Focused)
    ══════════════════════════════════════════ -->
    <section class="relative py-32 w-full overflow-hidden bg-[#0D2B1E]">
      <!-- Premium Background Effects -->
      <div class="absolute inset-0 z-0 bg-[url('https://www.transparenttextures.com/patterns/carbon-fibre.png')] opacity-10 font-[Inter]"></div>
      <div class="absolute top-0 right-0 w-[50rem] h-[50rem] bg-[var(--color-accent)]/10 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/4 pointer-events-none"></div>
      <div class="absolute bottom-0 left-0 w-[40rem] h-[40rem] bg-[var(--color-gold)]/10 rounded-full blur-[120px] translate-y-1/2 -translate-x-1/4 pointer-events-none"></div>
      
      <div class="max-w-7xl mx-auto px-6 md:px-8 relative z-10">
        <div class="flex flex-col lg:flex-row items-center justify-between gap-12 lg:gap-24">
          
          <!-- Text Content -->
          <div class="flex-1 text-center lg:text-left max-w-3xl">
            <h2 class="font-anuphan text-4xl md:text-5xl lg:text-7xl font-black text-white mb-8 leading-[1.05] tracking-tight">
              การท่องเที่ยวที่คุณ<br />
              <span class="text-[var(--color-gold)]">"วางใจได้"</span> ในทุกคลิก
            </h2>
            <p class="text-white/70 text-lg md:text-2xl font-medium leading-relaxed mb-0">
              ลบภาพการจองทริปที่แสนวุ่นวายออกไป — <span class="text-white border-b-2 border-[var(--color-gold)] italic">เราเปลี่ยนความกังวลของคุณ</span> ให้เป็นความตื่นเต้นที่จะได้ออกไปเจอโลกกว้าง
            </p>
          </div>

          <!-- Glassmorphism Action Card -->
          <div class="flex-1 w-full max-w-lg">
            <div class="relative group">
              <div class="absolute -inset-1 bg-gradient-to-r from-[var(--color-accent)] to-[var(--color-gold)] rounded-[3.5rem] blur opacity-20 group-hover:opacity-40 transition duration-1000"></div>
              
              <div class="relative bg-white/5 backdrop-blur-2xl p-10 md:p-14 rounded-[3.5rem] border border-white/10 shadow-3xl text-center">
                <div class="w-24 h-24 bg-[var(--color-accent)] rounded-[2rem] flex items-center justify-center mx-auto mb-8 shadow-2xl ring-1 ring-white/20 transform group-hover:rotate-6 transition-transform">
                  <span class="material-symbols-rounded text-white text-[48px]">search_check</span>
                </div>
                <h3 class="text-2xl md:text-4xl font-bold text-white mb-6">ค้นหาทริปที่ใช่สำหรับคุณ</h3>
                <p class="text-white/60 mb-10 font-medium text-lg">เริ่มต้นวางแผนการเดินทางครั้งใหม่กับเราวันนี้ สัมผัสความง่ายที่คุณไม่เคยเจอที่ไหนมาก่อน</p>
                
                <router-link
                  to="/trips"
                  class="w-full bg-white text-[#0D2B1E] hover:bg-[var(--color-gold)] hover:text-white py-6 rounded-2xl font-black text-xl transition-all duration-500 shadow-xl hover:shadow-[0_20px_40px_rgba(255,255,255,0.15)] flex items-center justify-center gap-3 cursor-pointer group/btn overflow-hidden"
                >
                  <span class="relative z-10">เริ่มวางแผนทริปวันนี้</span>
                  <span class="material-symbols-rounded relative z-10 text-2xl transform group-hover/btn:translate-x-2 transition-transform duration-500">travel_explore</span>
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
    <section class="py-32 md:py-48 bg-[var(--color-sand)] relative">
      <div class="absolute top-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-[var(--color-sand-dark)] to-transparent"></div>
      
      <div class="max-w-7xl mx-auto px-6 md:px-8">
        <div class="text-center mb-24 reveal-section">
          <div class="inline-flex items-center gap-3 justify-center text-[var(--color-accent)] font-bold tracking-wider uppercase text-xs mb-4">
            <span class="w-8 h-[2px] bg-[var(--color-accent)]"></span>
            วิสัยทัศน์ในอนาคต
            <span class="w-8 h-[2px] bg-[var(--color-accent)]"></span>
          </div>
          <h2 class="text-4xl md:text-5xl lg:text-7xl font-extrabold text-[var(--color-text-dark)] leading-tight">
            อนาคตการท่องเที่ยว<br/>ที่เราสร้างร่วมกัน
          </h2>
        </div>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8 md:gap-12 mt-16 md:mt-12 items-stretch">
          <div
            v-for="(vision, idx) in visions"
            :key="vision.title"
            class="bg-white p-8 md:p-10 pt-32 rounded-[3.5rem] shadow-lg hover:shadow-2xl transition-all duration-700 border border-white hover:border-[var(--color-accent)]/20 cursor-default group relative mt-24 text-center flex flex-col justify-between reveal-section"
            :class="{ 'lg:translate-y-12': idx % 2 === 1 }"
          >
            <!-- Image Wrapper (Out-of-bound) -->
            <div class="absolute -top-28 left-1/2 -translate-x-1/2 z-20 transition-all duration-700 group-hover:scale-110 group-hover:-translate-y-6">
              <img 
                :src="vision.image" 
                :alt="vision.title" 
                class="w-56 h-56 md:w-64 md:h-64 max-w-none object-contain drop-shadow-2xl"
              />
            </div>

            <div class="relative z-10 flex flex-col items-center h-full pt-4">
              <h3 class="text-2xl font-black text-[var(--color-text-dark)] mb-4 group-hover:text-[var(--color-accent)] transition-colors">{{ vision.title }}</h3>
              <p class="text-[var(--color-text-mid)] text-lg font-medium leading-relaxed mx-auto max-w-xs">{{ vision.desc }}</p>
              <!-- Decorative Line -->
              <div class="mt-auto pt-10">
                <div class="mx-auto w-12 h-1.5 bg-[var(--color-accent)]/20 group-hover:bg-[var(--color-accent)] group-hover:w-20 transition-all duration-700 rounded-full"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         FINAL CTA
    ══════════════════════════════════════════ -->
    <section class="py-24 md:py-40 px-6 md:px-8 bg-white overflow-hidden">
      <div class="relative rounded-[4rem] overflow-hidden max-w-7xl mx-auto reveal-section shadow-3xl">
        <div class="absolute inset-0">
          <img
            src="/images/landscape.webp"
            alt="Ready to explore"
            class="w-full h-full object-cover scale-105 motion-safe:animate-[subtle-zoom_30s_infinite_alternate]"
          />
          <div class="absolute inset-0 bg-gradient-to-br from-[#0D2B1E]/95 via-[#1A3A2E]/80 to-transparent"></div>
        </div>
        
        <!-- Premium UI Decorations -->
        <div class="absolute top-0 right-0 w-[40rem] h-[40rem] bg-[var(--color-accent)]/20 rounded-full blur-[100px] -translate-y-1/2 translate-x-1/4 pointer-events-none"></div>
        <div class="absolute bottom-0 left-0 w-[30rem] h-[30rem] bg-white/10 rounded-full blur-[80px] translate-y-1/2 -translate-x-1/4 pointer-events-none"></div>

        <div class="relative z-10 p-12 md:p-24 lg:p-32 text-left text-white max-w-4xl">
          <div class="inline-flex items-center gap-3 text-[var(--color-gold)] font-bold tracking-wider uppercase text-sm mb-10">
            <span class="w-12 h-[2px] bg-[var(--color-gold)]"></span>
            พร้อมจะออกไปลุยหรือยัง?
          </div>
          
          <h2 class="text-5xl md:text-6xl lg:text-8xl font-black leading-[1.05] mb-10 tracking-tight">
            ให้เราช่วยสร้าง<br/>
            <span class="text-transparent bg-clip-text bg-gradient-to-r from-white to-white/70 underline decoration-[var(--color-gold)] decoration-8">ความทรงจำ</span> ที่สวยงามของคุณ
          </h2>
          
          <p class="text-xl md:text-2xl text-white/80 leading-relaxed font-medium mb-16 max-w-2xl border-l-4 border-[var(--color-gold)] pl-8">
            เราดูแลทุกรายละเอียด เพื่อให้คุณได้ใช้เวลาที่มีค่าไปกับการ "ซึมซับบรรยากาศ" และ "สนุกกับการเดินทาง" อย่างเต็มที่
          </p>
          
          <div class="flex flex-col sm:flex-row gap-6">
            <router-link
              to="/trips"
              class="inline-flex items-center justify-center gap-4 bg-[var(--color-accent)] text-white px-12 py-6 rounded-[2rem] text-2xl font-black hover:bg-[#3D8F66] hover:shadow-[0_20px_50px_rgba(76,175,125,0.4)] transition-all duration-500 group/btn"
            >
              <span>เริ่มผจญภัยกับเราวันนี้</span>
              <span class="material-symbols-rounded text-3xl group-hover/btn:translate-x-2 transition-transform">explore</span>
            </router-link>
            <router-link
              to="/about"
              class="inline-flex items-center justify-center gap-4 bg-white/10 backdrop-blur-md border border-white/20 text-white px-12 py-6 rounded-[2rem] text-2xl font-black hover:bg-white/20 transition-all duration-500"
            >
              รู้จักเรามากขึ้น
            </router-link>
          </div>
          
          <!-- Quick Status -->
          <div class="mt-20 flex items-center gap-10 opacity-70">
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-white">verified_user</span>
              <span class="font-bold">จองปลอดภัย 100%</span>
            </div>
            <div class="flex items-center gap-3">
              <span class="material-symbols-rounded text-white">thumb_up</span>
              <span class="font-bold">พาร์ทเนอร์ที่ผ่านการตรวจสอบ</span>
            </div>
          </div>
        </div>
      </div>
    </section>

  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

const visions = ref([
  { image: '/images/no_wating.webp', title: 'เที่ยวได้ทันที', desc: 'ไม่ต้องรอคิว ไม่ต้องรอแอดมิน ทุกอย่างจัดการได้ด้วยตัวเองแบบเรียลไทม์', color: '#4CAF7D' },
  { image: '/images/no_hassle.webp', title: 'จองง่ายใน 1 นาที', desc: 'ออกแบบให้เรียบง่ายที่สุด เพื่อให้คุณใช้เวลาไปกับการเตรียมตัวเที่ยว ไม่ใช่การจอง', color: '#66C291' },
  { image: '/images/nohidden_show.webp', title: 'ราคาใสสะอาด', desc: 'สิ่งที่เห็นคือสิ่งที่คุณจ่าย ไม่มีค่าบริการแฝงหรือความซับซ้อนให้ปวดหัว', color: '#C8963E' },
  { image: '/images/no_lucky.webp', title: 'มั่นใจได้ทุกทริป', desc: 'ระบบที่เชื่อถือได้ พร้อมทีมงานดูแล เพื่อให้ทุกการเดินทางของคุณราบรื่นที่สุด', color: '#007B8F' },
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
  from { opacity: 0; transform: translateY(60px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* Animations */
@keyframes subtle-zoom {
  from { transform: scale(1); }
  to   { transform: scale(1.1); }
}

@keyframes bounce-subtle {
  0%, 100% { transform: translateY(0); }
  50%      { transform: translateY(-10px); }
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
  transform: translateY(40px);
  transition: opacity 1s cubic-bezier(0.16, 1, 0.3, 1), transform 1s cubic-bezier(0.16, 1, 0.3, 1);
}

.reveal-section.is-visible {
  opacity: 1;
  transform: translateY(0);
}

.animate-bounce-subtle {
  animation: bounce-subtle 3s ease-in-out infinite;
}

/* Respect reduced motion */
@media (prefers-reduced-motion: reduce) {
  .hero-content { animation: none; }
  .scroll-dot { animation: none; }
  .reveal-section { opacity: 1; transform: none; transition: none; }
  .animate-bounce-subtle { animation: none; }
}
</style>
