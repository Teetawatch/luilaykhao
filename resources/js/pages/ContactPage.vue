<template>
  <div class="contact-page font-anuphan bg-[var(--color-sand)] min-h-screen">
    
    <!-- Hero Section -->
    <section class="relative py-24 md:py-32 bg-[#0D2B1E] overflow-hidden">
      <div class="absolute inset-0">
        <div class="absolute inset-0 bg-gradient-to-br from-[#0D2B1E] via-[#1A3A2E] to-[#2D5A3D] opacity-90"></div>
        <div class="absolute top-0 right-0 w-[600px] h-[600px] bg-[var(--color-accent)]/10 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/4"></div>
      </div>
      
      <div class="relative z-10 max-w-7xl mx-auto px-6 md:px-8 text-center">
        <div class="inline-flex items-center gap-3 justify-center text-[var(--color-gold)] font-bold tracking-wider uppercase text-sm mb-6">
          <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
          ติดต่อเรา
          <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
        </div>
        <h1 class="text-4xl md:text-6xl font-extrabold text-white mb-6">
          มีคำถามหรือข้อสงสัยใช่ไหม?
        </h1>
        <p class="text-lg md:text-xl text-white/80 max-w-2xl mx-auto leading-relaxed">
          เราพร้อมดูแลและตอบทุกคำถามของคุณ ไม่ว่าจะเป็นเรื่องทริป การจอง หรือข้อเสนอแนะต่างๆ ทีมงานลุยเลเขายินดีให้บริการครับ
        </p>
      </div>
    </section>

    <!-- Contact Info & Form -->
    <section class="py-20 md:py-32 px-6 md:px-8">
      <div class="max-w-7xl mx-auto">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-20">
          
          <!-- Contact Details -->
          <div class="lg:col-span-5 space-y-12">
            <div>
              <h2 class="text-3xl font-bold text-[var(--color-text-dark)] mb-8 flex items-center gap-3">
                ช่องทางการติดต่อ
                <div class="h-1 flex-grow bg-sand-dark rounded-full overflow-hidden">
                  <div class="h-full bg-primary w-1/4 rounded-full"></div>
                </div>
              </h2>
              
              <div class="space-y-8">
                <!-- Phone -->
                <div class="flex gap-5 group">
                  <div class="w-14 h-14 bg-white rounded-2xl shadow-sm border border-sand-dark/50 flex items-center justify-center shrink-0 group-hover:bg-primary group-hover:border-primary transition-all duration-300">
                    <span class="material-symbols-rounded text-primary group-hover:text-white transition-colors">call</span>
                  </div>
                  <div>
                    <p class="text-sm font-bold text-text-muted uppercase tracking-widest mb-1">เบอร์โทรศัพท์</p>
                    <a href="tel:0812345678" class="text-xl font-bold text-text-dark hover:text-primary transition-colors">062-612-6006</a>
                    <p class="text-sm text-text-muted mt-1 text-xs">จันทร์ - อาทิตย์, 09:00 - 20:00 น.</p>
                  </div>
                </div>

                <!-- Email -->
                <div class="flex gap-5 group">
                  <div class="w-14 h-14 bg-white rounded-2xl shadow-sm border border-sand-dark/50 flex items-center justify-center shrink-0 group-hover:bg-primary group-hover:border-primary transition-all duration-300">
                    <span class="material-symbols-rounded text-primary group-hover:text-white transition-colors">mail</span>
                  </div>
                  <div>
                    <p class="text-sm font-bold text-text-muted uppercase tracking-widest mb-1">อีเมล</p>
                    <a href="mailto:hello@luilaykhao.com" class="text-xl font-bold text-text-dark hover:text-primary transition-colors">info@luilaykhao.com</a>
                  </div>
                </div>

                <!-- LINE -->
                <div class="flex gap-5 group">
                  <div class="w-14 h-14 bg-white rounded-2xl shadow-sm border border-sand-dark/50 flex items-center justify-center shrink-0 group-hover:bg-[#06C755] group-hover:border-[#06C755] transition-all duration-300">
                    <span class="material-symbols-rounded text-primary group-hover:text-white transition-colors">chat_bubble</span>
                  </div>
                  <div>
                    <p class="text-sm font-bold text-text-muted uppercase tracking-widest mb-1">LINE Official</p>
                    <a href="#" class="text-xl font-bold text-text-dark hover:text-[#06C755] transition-colors">@luilaykhao</a>
                  </div>
                </div>

                <!-- Location -->
                <div class="flex gap-5 group">
                  <div class="w-14 h-14 bg-white rounded-2xl shadow-sm border border-sand-dark/50 flex items-center justify-center shrink-0 group-hover:bg-primary group-hover:border-primary transition-all duration-300">
                    <span class="material-symbols-rounded text-primary group-hover:text-white transition-colors">location_on</span>
                  </div>
                  <div>
                    <p class="text-sm font-bold text-text-muted uppercase tracking-widest mb-1">ที่ตั้งสำนักงาน</p>
                    <p class="text-lg font-bold text-text-dark leading-snug">
                      123 อาคารลุยเลเขา ชั้น 5 ถ.สุขุมวิท<br />แขวงคลองเตย เขตคลองเตย กรุงเทพฯ 10110
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Social Links -->
            <div class="pt-8">
              <h3 class="text-sm font-bold text-text-muted uppercase tracking-widest mb-6">ติดตามเราได้ที่</h3>
              <div class="flex gap-4">
                <a v-for="social in socials" :key="social.name" :href="social.link" 
                  class="w-12 h-12 rounded-xl bg-white border border-sand-dark flex items-center justify-center text-text-mid hover:text-white hover:bg-primary hover:border-primary transition-all duration-300 shadow-sm">
                  <i :class="social.icon" class="text-xl"></i>
                </a>
              </div>
            </div>
          </div>

          <!-- Contact Form -->
          <div class="lg:col-span-7">
            <div class="bg-white rounded-[2.5rem] p-8 md:p-12 shadow-xl shadow-sand-dark/20 border border-sand-dark/30">
              <h3 class="text-2xl font-bold text-text-dark mb-2">ส่งข้อความถึงเรา</h3>
              <p class="text-text-muted mb-10">กรอกรายละเอียดด้านล่าง แล้วเราจะติดต่อกลับหาคุณโดยเร็วที่สุด</p>
              
              <form @submit.prevent="handleSubmit" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <div class="space-y-2">
                    <label class="text-sm font-bold text-text-dark ml-1">ชื่อ-นามสกุล *</label>
                    <input v-model="form.name" type="text" required placeholder="สมชาย ใจดี" 
                      class="w-full px-6 py-4 bg-sand/50 border border-sand-dark/60 rounded-2xl focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all" />
                  </div>
                  <div class="space-y-2">
                    <label class="text-sm font-bold text-text-dark ml-1">เบอร์โทรศัพท์ *</label>
                    <input v-model="form.phone" type="tel" required placeholder="08x-xxx-xxxx" 
                      class="w-full px-6 py-4 bg-sand/50 border border-sand-dark/60 rounded-2xl focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all" />
                  </div>
                </div>
                
                <div class="space-y-2">
                  <label class="text-sm font-bold text-text-dark ml-1">อีเมล</label>
                  <input v-model="form.email" type="email" placeholder="example@mail.com" 
                    class="w-full px-6 py-4 bg-sand/50 border border-sand-dark/60 rounded-2xl focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all" />
                </div>

                <div class="space-y-2">
                  <label class="text-sm font-bold text-text-dark ml-1">หัวข้อติดต่อ *</label>
                  <select v-model="form.subject" required 
                    class="w-full px-6 py-4 bg-sand/50 border border-sand-dark/60 rounded-2xl focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all appearance-none cursor-pointer">
                    <option value="" disabled>เลือกหัวข้อการติดต่อ</option>
                    <option value="booking">สอบถามเรื่องการจองทริป</option>
                    <option value="payment">ปัญหาการชำระเงิน</option>
                    <option value="partnership">ร่วมเป็นพาร์ทเนอร์</option>
                    <option value="general">สอบถามทั่วไป / แนะนำบริการ</option>
                    <option value="other">อื่นๆ</option>
                  </select>
                </div>

                <div class="space-y-2">
                  <label class="text-sm font-bold text-text-dark ml-1">ข้อความจากคุณ *</label>
                  <textarea v-model="form.message" rows="5" required placeholder="เขียนรายละเอียดที่ต้องการสอบถาม..." 
                    class="w-full px-6 py-4 bg-sand/50 border border-sand-dark/60 rounded-2xl focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all resize-none"></textarea>
                </div>

                <button type="submit" :disabled="submitting"
                  class="w-full py-5 bg-primary text-white rounded-2xl font-bold text-lg hover:bg-primary-mid transition-all duration-300 shadow-lg shadow-primary/20 active:scale-95 disabled:opacity-70 disabled:cursor-not-allowed">
                  <span v-if="submitting" class="flex items-center justify-center gap-2">
                    <i class="fas fa-circle-notch fa-spin"></i> กำลังส่ง...
                  </span>
                  <span v-else>ส่งข้อความ</span>
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Map Section -->
    <section class="h-[450px] w-full bg-sand-dark overflow-hidden relative grayscale hover:grayscale-0 transition-all duration-700">
       <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m14!1m1!2se!1m2!1s0x30e29f17666270b7:0x2287413697eb509!2z4LiB4Lij4Li44LiH4LmA4LiX4Lie4Lih4Lir4Liy4LiZ4LiE4Lij!5e0!3m2!1sth!2sth!4v1712215200000!5m2!1sth!2sth" 
        width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
    </section>

  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'

const submitting = ref(false)
const form = reactive({
  name: '',
  phone: '',
  email: '',
  subject: '',
  message: ''
})

const socials = [
  { name: 'Facebook', icon: 'fab fa-facebook-f', link: '#' },
  { name: 'Instagram', icon: 'fab fa-instagram', link: '#' },
  { name: 'TikTok', icon: 'fab fa-tiktok', link: '#' },
  { name: 'YouTube', icon: 'fab fa-youtube', link: '#' }
]

async function handleSubmit() {
  submitting.value = true
  // Simulate API call
  await new Promise(resolve => setTimeout(resolve, 1500))
  alert('ขอบคุณที่ส่งข้อความถึงเรา! เราจะรีบติดต่อกลับหาคุณโดยเร็วที่สุด')
  
  // Reset form
  form.name = ''
  form.phone = ''
  form.email = ''
  form.subject = ''
  form.message = ''
  submitting.value = false
}
</script>

<style scoped>
/* Page transition or component specific styles */
.contact-page {
  animation: fadeIn 0.8s ease-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
</style>
