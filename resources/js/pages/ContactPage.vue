<template>
  <div class="contact-page font-anuphan bg-[var(--color-sand)] min-h-screen">
    
    <!-- Hero Section -->
    <section class="relative py-16 md:py-24 bg-[#0D2B1E] overflow-hidden">
      <div class="absolute inset-0">
        <div class="absolute inset-0 bg-gradient-to-br from-[#0D2B1E] via-[#1A3A2E] to-[#2D5A3D] opacity-90"></div>
        <div class="absolute top-0 right-0 w-[600px] h-[600px] bg-[var(--color-accent)]/10 rounded-full blur-[120px] -translate-y-1/2 translate-x-1/4"></div>
      </div>
      
      <div class="relative z-10 max-w-7xl mx-auto px-6 md:px-8 text-center">
        <div class="inline-flex items-center gap-3 justify-center text-[var(--color-gold)] font-bold tracking-wider uppercase text-sm mb-6">
          <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
          พร้อมดูแลคุณตลอดการเดินทาง
          <span class="w-8 h-[2px] bg-[var(--color-gold)]"></span>
        </div>
        <h1 class="text-3xl md:text-5xl font-extrabold text-white mb-5">
          มีคำถามเกี่ยวกับทริป?<br />เราพร้อมช่วยคุณวางแผน
        </h1>
        <p class="text-base md:text-lg text-white/80 max-w-2xl mx-auto leading-relaxed">
          ทักมาหาเราได้เลย ทีมงาน "ลุยเลเขา" สแตนด์บายรอช่วยเหลือคุณอยู่ ไม่ว่าจะเป็นการเลือกทริปที่ใช่ หรือสอบถามสิ่งที่สงสัย เรายินดีให้คำปรึกษาฟรีครับ
        </p>
      </div>
    </section>

    <!-- Contact Info & Form -->
    <section class="py-16 md:py-24 px-6 md:px-8">
      <div class="max-w-7xl mx-auto">
        <!-- Quick Contact Buttons -->
        <div class="flex flex-col sm:flex-row gap-4 mb-16 justify-center lg:justify-start">
          <a href="https://line.me/ti/p/@luilaykhao" target="_blank" 
             class="flex-1 max-w-xs flex items-center justify-center gap-3 px-8 py-4 bg-[#06C755] text-white rounded-2xl font-bold text-lg hover:bg-[#05b34c] hover:-translate-y-1 transition-all duration-300">
            <i class="fab fa-line text-2xl"></i>
            แชทผ่าน LINE
          </a>
          <a href="tel:0626126006" 
             class="flex-1 max-w-xs flex items-center justify-center gap-3 px-8 py-4 bg-primary text-white rounded-2xl font-bold text-lg hover:bg-primary-mid hover:-translate-y-1 transition-all duration-300">
            <span class="material-symbols-rounded">call</span>
            โทรหาเราเลย
          </a>
        </div>

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
                <!-- LINE (Highlighted) -->
                <div class="flex gap-5 group p-6 bg-white rounded-[2rem] border border-[#06C755]/20 transition-all duration-300">
                  <div class="w-14 h-14 bg-[#06C755] rounded-2xl flex items-center justify-center shrink-0">
                    <i class="fab fa-line text-2xl text-white"></i>
                  </div>
                  <div>
                    <p class="text-sm font-bold text-[#06C755] uppercase tracking-widest mb-1">LINE Official (เร็วที่สุด)</p>
                    <a href="https://line.me/R/ti/p/@luilaykhao" target="_blank" class="text-xl font-bold text-text-dark hover:text-[#06C755] transition-colors">@luilaykhao</a>
                    <p class="text-sm text-text-muted mt-1">สอบถามได้ตลอด 24 ชม. ตอบกลับไวมาก</p>
                  </div>
                </div>

                <!-- Phone -->
                <div class="flex gap-5 group px-6">
                  <div class="w-14 h-14 bg-white rounded-2xl border border-sand-dark/50 flex items-center justify-center shrink-0 group-hover:bg-primary group-hover:border-primary transition-all duration-300">
                    <span class="material-symbols-rounded text-primary group-hover:text-white transition-colors">call</span>
                  </div>
                  <div>
                    <p class="text-sm font-bold text-text-muted uppercase tracking-widest mb-1">เบอร์โทรศัพท์</p>
                    <a href="tel:0626126006" class="text-xl font-bold text-text-dark hover:text-primary transition-colors">062-612-6006</a>
                    <p class="text-sm text-text-muted mt-1">จันทร์ - อาทิตย์, 09:00 - 20:00 น.</p>
                  </div>
                </div>

                <!-- Email -->
                <div class="flex gap-5 group px-6">
                  <div class="w-14 h-14 bg-white rounded-2xl border border-sand-dark/50 flex items-center justify-center shrink-0 group-hover:bg-primary group-hover:border-primary transition-all duration-300">
                    <span class="material-symbols-rounded text-primary group-hover:text-white transition-colors">mail</span>
                  </div>
                  <div>
                    <p class="text-sm font-bold text-text-muted uppercase tracking-widest mb-1">อีเมล</p>
                    <a href="mailto:luilaykhao.info@gmail.com" class="text-xl font-bold text-text-dark hover:text-primary transition-colors">luilaykhao.info@gmail.com</a>
                  </div>
                </div>

                <!-- Location -->
                <div class="flex gap-5 group px-6">
                  <div class="w-14 h-14 bg-white rounded-2xl border border-sand-dark/50 flex items-center justify-center shrink-0 group-hover:bg-primary group-hover:border-primary transition-all duration-300">
                    <span class="material-symbols-rounded text-primary group-hover:text-white transition-colors">location_on</span>
                  </div>
                  <div>
                    <p class="text-sm font-bold text-text-muted uppercase tracking-widest mb-1">ที่ตั้งสำนักงาน</p>
                    <p class="text-lg font-bold text-text-dark leading-snug">
                      ถนนทางรถไฟเก่า แขวงบางนาใต้<br />เขตบางนา กรุงเทพมหานคร 10260
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Social Links -->
            <div class="pt-8 px-6">
              <h3 class="text-sm font-bold text-text-muted uppercase tracking-widest mb-6">ติดตามเราได้ที่</h3>
              <div class="flex gap-4">
                <a v-for="social in socials" :key="social.name" :href="social.link" target="_blank" rel="noopener"
                  class="w-12 h-12 rounded-xl bg-white border border-sand-dark flex items-center justify-center text-text-mid hover:text-white hover:bg-primary hover:border-primary transition-all duration-300">
                  <i :class="social.icon" class="text-xl"></i>
                </a>
              </div>
            </div>
          </div>

          <!-- Contact Form -->
          <div class="lg:col-span-7">
            <div class="bg-white rounded-[2.5rem] p-8 md:p-12 shadow-sand-dark/30 border border-sand-dark/20 relative overflow-hidden">
              <!-- Background Accent -->
              <div class="absolute top-0 right-0 w-32 h-32 bg-primary/5 rounded-full -translate-y-1/2 translate-x-1/2"></div>
              
              <div class="relative z-10">
                <h3 class="text-2xl font-bold text-text-dark mb-2">ส่งข้อความหาเรา</h3>
                <p class="text-text-muted mb-10">เล่ารายละเอียดที่คุณต้องการให้เราช่วย ทีมงานจะติดต่อกลับหาคุณโดยเร็วที่สุด</p>
                
                <!-- Success Message -->
                <div v-if="submitted" 
                     class="mb-8 p-6 bg-green-50 border border-green-100 rounded-2xl flex items-center gap-4 animate-in fade-in zoom-in duration-300">
                  <div class="w-10 h-10 bg-green-500 rounded-full flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-white">check</span>
                  </div>
                  <div>
                    <p class="font-bold text-green-800">ส่งข้อความเรียบร้อยแล้ว!</p>
                    <p class="text-sm text-green-700">เราได้รับข้อมูลของคุณแล้ว ทีมงานจะติดต่อกลับภายใน 24 ชม.</p>
                  </div>
                </div>

                <form v-else @submit.prevent="handleSubmit" class="space-y-6">
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="group space-y-2">
                      <label class="text-sm font-bold text-text-dark ml-1 group-focus-within:text-primary transition-colors">ชื่อ-นามสกุล</label>
                      <input v-model="form.name" type="text" required placeholder="เช่น สมชาย ใจดี" 
                        class="w-full px-6 py-4 bg-sand/30 border border-sand-dark/40 rounded-2xl focus:outline-none focus:ring-4 focus:ring-primary/10 focus:border-primary focus:bg-white transition-all duration-300" />
                    </div>
                    <div class="group space-y-2">
                      <label class="text-sm font-bold text-text-dark ml-1 group-focus-within:text-primary transition-colors">เบอร์โทรศัพท์ (ถ้ามี)</label>
                      <input v-model="form.phone" type="tel" placeholder="08x-xxx-xxxx" 
                        class="w-full px-6 py-4 bg-sand/30 border border-sand-dark/40 rounded-2xl focus:outline-none focus:ring-4 focus:ring-primary/10 focus:border-primary focus:bg-white transition-all duration-300" />
                    </div>
                  </div>
                  
                  <div class="group space-y-2">
                    <label class="text-sm font-bold text-text-dark ml-1 group-focus-within:text-primary transition-colors">อีเมลที่ใช้ติดต่อ</label>
                    <input v-model="form.email" type="email" required placeholder="example@mail.com" 
                      class="w-full px-6 py-4 bg-sand/30 border border-sand-dark/40 rounded-2xl focus:outline-none focus:ring-4 focus:ring-primary/10 focus:border-primary focus:bg-white transition-all duration-300" />
                  </div>

                  <div v-if="showOptionalFields" class="space-y-2 animate-in fade-in duration-300">
                    <label class="text-sm font-bold text-text-dark ml-1">เรื่องที่ต้องการติดต่อ (ระบุถ้าต้องการให้เจาะจง)</label>
                    <select v-model="form.subject" 
                      class="w-full px-6 py-4 bg-sand/30 border border-sand-dark/40 rounded-2xl focus:outline-none focus:ring-4 focus:ring-primary/10 focus:border-primary bg-white transition-all appearance-none cursor-pointer">
                      <option value="">เลือกหัวข้อติดต่อ (ไม่ระบุก็ได้)</option>
                      <option value="booking">สอบถามเรื่องการจองทริป</option>
                      <option value="payment">ปัญหาการชำระเงิน</option>
                      <option value="partnership">ร่วมเป็นพาร์ทเนอร์</option>
                      <option value="general">สอบถามทั่วไป / แนะนำบริการ</option>
                      <option value="other">อื่นๆ</option>
                    </select>
                  </div>

                  <!-- Partnership Details -->
                  <div v-if="showOptionalFields && form.subject === 'partnership'"
                       class="space-y-6 p-6 bg-primary/5 border border-primary/15 rounded-3xl animate-in fade-in duration-300">
                    <div class="flex items-center gap-2 text-primary font-bold">
                      <span class="material-symbols-rounded">handshake</span>
                      ข้อมูลสำหรับพาร์ทเนอร์สตาฟเดินป่า
                    </div>

                    <div class="group space-y-2">
                      <label class="text-sm font-bold text-text-dark ml-1 group-focus-within:text-primary transition-colors">ป่าที่เคยไปเดิน</label>
                      <textarea v-model="form.forests_hiked" rows="4" placeholder="เช่น เขาหลวง (นครศรีธรรมราช), ภูสอยดาว, เขาช้างเผือก, ป่าฮาลา-บาลา ฯลฯ เล่าประสบการณ์ของคุณได้เลย"
                        class="w-full px-6 py-4 bg-white border border-sand-dark/40 rounded-2xl focus:outline-none focus:ring-4 focus:ring-primary/10 focus:border-primary transition-all duration-300 resize-none"></textarea>
                    </div>

                    <div class="space-y-2">
                      <label class="text-sm font-bold text-text-dark ml-1">แนบรูปภาพ (รูปตอนไปเดินป่า / ใบเซอร์ / อื่นๆ)</label>
                      <label class="flex flex-col items-center justify-center gap-2 px-6 py-8 bg-white border-2 border-dashed border-sand-dark/60 rounded-2xl cursor-pointer hover:border-primary hover:bg-primary/5 transition-all duration-300 text-center">
                        <span class="material-symbols-rounded text-3xl text-primary">add_photo_alternate</span>
                        <span class="text-sm font-bold text-text-dark">เลือกรูปภาพ</span>
                        <span class="text-xs text-text-muted">รองรับ JPG, PNG (สูงสุด 8MB ต่อรูป)</span>
                        <input type="file" accept="image/*" multiple class="hidden" @change="onImagesSelected" />
                      </label>

                      <div v-if="imagePreviews.length" class="grid grid-cols-3 sm:grid-cols-4 gap-3 pt-2">
                        <div v-for="(preview, idx) in imagePreviews" :key="idx" class="relative aspect-square rounded-xl overflow-hidden border border-sand-dark group/img">
                          <img :src="preview" class="w-full h-full object-cover" />
                          <button type="button" @click="removeImage(idx)"
                            class="absolute top-1 right-1 w-6 h-6 bg-black/60 text-white rounded-full flex items-center justify-center opacity-0 group-hover/img:opacity-100 transition-opacity hover:bg-red-500">
                            <span class="material-symbols-rounded text-base">close</span>
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="group space-y-2">
                    <label class="text-sm font-bold text-text-dark ml-1 group-focus-within:text-primary transition-colors">เล่าให้เราฟังว่าคุณอยากไปเที่ยวแบบไหน...</label>
                    <textarea v-model="form.message" rows="5" required placeholder="เช่น อยากไปเดินป่าช่วงหน้าหนาว, สนใจทริปไหนเป็นพิเศษ หรือมีคำถามเพิ่มเติม..." 
                      class="w-full px-6 py-4 bg-sand/30 border border-sand-dark/40 rounded-2xl focus:outline-none focus:ring-4 focus:ring-primary/10 focus:border-primary focus:bg-white transition-all duration-300 resize-none"></textarea>
                  </div>

                  <div class="pt-4">
                    <button type="submit" :disabled="submitting"
                      class="w-full py-5 bg-primary text-white rounded-2xl font-bold text-lg hover:bg-primary-mid transition-all duration-300 shadow-primary/20 active:scale-[0.98] disabled:opacity-70 disabled:cursor-not-allowed group">
                      <span v-if="submitting" class="flex items-center justify-center gap-2">
                        <i class="fas fa-circle-notch fa-spin"></i> กำลังส่งข้อมูล...
                      </span>
                      <span v-else class="flex items-center justify-center gap-2 group-hover:gap-4 transition-all">
                        ให้เราช่วยวางแผนทริป
                        <span class="material-symbols-rounded">arrow_forward</span>
                      </span>
                    </button>
                    
                    <!-- Trust Signals -->
                    <div class="mt-8 flex flex-wrap justify-between items-center gap-4 px-2">
                      <div class="flex items-center gap-2 text-xs text-text-muted">
                        <span class="material-symbols-rounded text-green-500 text-lg">check_circle</span>
                        ตอบกลับภายใน 24 ชั่วโมง
                      </div>
                      <div class="flex items-center gap-2 text-xs text-text-muted">
                        <span class="material-symbols-rounded text-blue-500 text-lg">security</span>
                        ไม่มีสแปม & รักษาความเป็นส่วนตัว
                      </div>
                      <div class="flex items-center gap-2 text-xs text-text-muted">
                        <span class="material-symbols-rounded text-amber-500 text-lg">support_agent</span>
                        ทีมงานคนจริง พร้อมช่วยแก้ปัญหา
                      </div>
                    </div>
                  </div>
                </form>

                <div v-if="!submitted" class="mt-6 text-center">
                  <button @click="showOptionalFields = !showOptionalFields" class="text-sm font-semibold text-text-muted hover:text-primary transition-colors underline underline-offset-4">
                    {{ showOptionalFields ? 'ซ่อนตัวเลือกเสริม' : 'เพิ่มข้อมูลเพิ่มเติม (หัวข้อติดต่อ/พาร์ทเนอร์)' }}
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Map Section -->
    <section class="h-[500px] w-full bg-sand-dark overflow-hidden relative group">
       <iframe src="https://www.google.com/maps/embed?pb=!1m14!1m12!1m3!1d1142.796759501285!2d100.58578633819413!3d13.667300090214399!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!5e0!3m2!1sth!2sth!4v1775908057144!5m2!1sth!2sth" 
        width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"
        class="grayscale group-hover:grayscale-0 transition-all duration-700"></iframe>
       
       <!-- Map Overlay Button -->
       <div class="absolute inset-0 flex items-center justify-center pointer-events-none group-hover:opacity-100 transition-opacity">
         <a href="https://maps.google.com/?q=บางนา+กรุงเทพ" target="_blank" 
            class="pointer-events-auto bg-white/90 backdrop-blur-sm text-text-dark px-8 py-3 rounded-full font-bold border border-sand-dark/20 hover:bg-white hover:scale-110 transition-all flex items-center gap-2">
           <span class="material-symbols-rounded text-primary">map</span>
           เปิดใน Google Maps
         </a>
       </div>
    </section>

  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import axios from 'axios'

const submitting = ref(false)
const submitted = ref(false)
const showOptionalFields = ref(false)

const form = reactive({
  name: '',
  phone: '',
  email: '',
  subject: '',
  message: '',
  forests_hiked: ''
})

const images = ref([])
const imagePreviews = ref([])

function onImagesSelected(e) {
  const files = Array.from(e.target.files || [])
  for (const file of files) {
    images.value.push(file)
    imagePreviews.value.push(URL.createObjectURL(file))
  }
  e.target.value = ''
}

function removeImage(idx) {
  URL.revokeObjectURL(imagePreviews.value[idx])
  images.value.splice(idx, 1)
  imagePreviews.value.splice(idx, 1)
}

const socials = [
  { name: 'Facebook', icon: 'fab fa-facebook-f', link: 'https://www.facebook.com/p/%E0%B8%A5%E0%B8%B8%E0%B8%A2%E0%B9%80%E0%B8%A5%E0%B9%80%E0%B8%82%E0%B8%B2-Luilaykhao-61572124170207/' },
  { name: 'Instagram', icon: 'fab fa-instagram', link: 'https://www.instagram.com/luilaykhao/' },
  { name: 'TikTok', icon: 'fab fa-tiktok', link: 'https://www.tiktok.com/@luilaykhao' }
]

async function handleSubmit() {
  submitting.value = true
  
  try {
    const formData = new FormData()
    formData.append('name', form.name)
    formData.append('phone', form.phone || '')
    formData.append('email', form.email)
    formData.append('subject', form.subject || 'ทั่วไป')
    formData.append('message', form.message)

    if (form.subject === 'partnership') {
      formData.append('partner_type', 'trekking_staff')
      formData.append('forests_hiked', form.forests_hiked || '')
      images.value.forEach((file) => formData.append('images[]', file))
    }

    await axios.post('/api/v1/contacts', formData)

    submitted.value = true
    
  } catch (error) {
    console.error('Submit error:', error)
    alert('เกิดข้อผิดพลาดในการส่งข้อความ กรุณาลองใหม่อีกครั้ง')
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.contact-page {
  animation: fadeIn 0.8s ease-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

textarea::-webkit-scrollbar {
  width: 6px;
}
textarea::-webkit-scrollbar-track {
  background: transparent;
}
textarea::-webkit-scrollbar-thumb {
  background: #E2E8F0;
  border-radius: 10px;
}
</style>
