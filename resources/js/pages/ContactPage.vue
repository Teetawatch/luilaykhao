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
    <section class="py-10 md:py-16 px-6 md:px-8">
      <div class="max-w-7xl mx-auto">
        <!-- Quick Contact Buttons -->
        <div class="flex flex-col sm:flex-row gap-3 mb-12 justify-center lg:justify-start">
          <a href="https://line.me/ti/p/@luilaykhao" target="_blank"
             class="flex-1 max-w-xs flex items-center justify-center gap-3 px-8 py-4 bg-[#06C755] text-white rounded-2xl font-bold text-lg hover:bg-[#05b34c] active:scale-[0.98] transition-all">
            <i class="fab fa-line text-2xl"></i>
            แชทผ่าน LINE
          </a>
          <a href="tel:0626126006"
             class="flex-1 max-w-xs flex items-center justify-center gap-3 px-8 py-4 bg-primary text-white rounded-2xl font-bold text-lg hover:bg-primary-mid active:scale-[0.98] transition-all">
            <span class="material-symbols-rounded">call</span>
            โทร 062-612-6006
          </a>
        </div>

        <!-- คนที่ล็อกอินแล้วมีเธรดช่วยเหลือที่ทีมงานเห็นประวัติการจอง — ดีกว่าอีเมลกล่องรวม -->
        <router-link v-if="auth.isLoggedIn" to="/support"
          class="mb-12 flex items-start gap-4 p-5 md:p-6 bg-white rounded-[2rem] border border-primary/20 hover:border-primary transition-colors">
          <div class="w-12 h-12 rounded-2xl bg-primary/10 flex items-center justify-center shrink-0">
            <span class="material-symbols-rounded text-primary">support_agent</span>
          </div>
          <div class="min-w-0 flex-1">
            <p class="font-bold text-text-dark">มีเรื่องเกี่ยวกับการจองของคุณ? คุยในศูนย์ช่วยเหลือ</p>
            <p class="mt-1 text-sm text-text-muted leading-relaxed">
              ทีมงานเห็นประวัติการจองของคุณอยู่แล้ว ไม่ต้องเล่าใหม่ และข้อความอยู่ในเธรดเดียวกันทั้งหมด
            </p>
          </div>
          <span class="material-symbols-rounded text-text-muted shrink-0">chevron_right</span>
        </router-link>

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
                    <p class="text-sm font-bold text-[#06C755] mb-1">LINE Official</p>
                    <a href="https://line.me/R/ti/p/@luilaykhao" target="_blank" class="text-xl font-bold text-text-dark hover:text-[#06C755] transition-colors">@luilaykhao</a>
                    <p class="text-sm text-text-muted mt-1">ทักไว้ได้ตลอด ทีมงานตอบในเวลาทำการ</p>
                  </div>
                </div>

                <!-- Phone -->
                <div class="flex gap-5 group px-6">
                  <div class="w-14 h-14 bg-white rounded-2xl border border-sand-dark/50 flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-primary">call</span>
                  </div>
                  <div>
                    <p class="text-sm font-bold text-text-muted uppercase tracking-widest mb-1">เบอร์โทรศัพท์</p>
                    <a href="tel:0626126006" class="text-xl font-bold text-text-dark hover:text-primary transition-colors">062-612-6006</a>
                    <p class="text-sm text-text-muted mt-1">จันทร์ - อาทิตย์, 09:00 - 20:00 น.</p>
                  </div>
                </div>

                <!-- Email -->
                <div class="flex gap-5 group px-6">
                  <div class="w-14 h-14 bg-white rounded-2xl border border-sand-dark/50 flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-primary">mail</span>
                  </div>
                  <div>
                    <p class="text-sm font-bold text-text-muted uppercase tracking-widest mb-1">อีเมล</p>
                    <a href="mailto:luilaykhao.info@gmail.com" class="text-xl font-bold text-text-dark hover:text-primary transition-colors">luilaykhao.info@gmail.com</a>
                  </div>
                </div>

                <!-- Location -->
                <div class="flex gap-5 group px-6">
                  <div class="w-14 h-14 bg-white rounded-2xl border border-sand-dark/50 flex items-center justify-center shrink-0">
                    <span class="material-symbols-rounded text-primary">location_on</span>
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
            <div class="bg-white rounded-[2.5rem] p-8 md:p-12 border border-sand-dark/20">
              <div>
                <h3 class="text-2xl font-bold text-text-dark mb-2">ส่งข้อความหาเรา</h3>
                <p class="text-text-muted mb-10">เขียนรายละเอียดที่ต้องการให้ช่วย ทีมงานจะติดต่อกลับทางอีเมลหรือเบอร์ที่ให้ไว้</p>

                <!-- Success Message -->
                <div v-if="submitted"
                     class="mb-8 p-6 bg-green-50 border border-green-100 rounded-2xl">
                  <div class="flex items-center gap-4">
                    <div class="w-10 h-10 bg-green-600 rounded-full flex items-center justify-center shrink-0">
                      <span class="material-symbols-rounded text-white">check</span>
                    </div>
                    <div>
                      <p class="font-bold text-green-800">ส่งข้อความเรียบร้อยแล้ว</p>
                      <p class="text-sm text-green-700">ทีมงานจะติดต่อกลับทาง {{ form.email }} ในเวลาทำการครับ</p>
                    </div>
                  </div>
                  <button type="button" @click="resetForm"
                    class="mt-4 text-sm font-bold text-primary underline underline-offset-4">
                    ส่งอีกข้อความ
                  </button>
                </div>

                <form v-else @submit.prevent="handleSubmit" class="space-y-6">
                  <!-- หัวข้อมาก่อน เพราะเป็นสิ่งที่ทีมงานใช้คัดแยกเรื่อง และเป็นตัวกำหนดช่องที่เหลือ -->
                  <div class="space-y-2">
                    <label for="contact-subject" class="text-sm font-bold text-text-dark ml-1">เรื่องที่ต้องการติดต่อ</label>
                    <select id="contact-subject" v-model="form.subject"
                      class="w-full px-6 py-4 bg-sand/30 border border-sand-dark/40 rounded-2xl focus:outline-none focus:border-primary focus:bg-white transition-colors appearance-none cursor-pointer">
                      <option value="">เลือกหัวข้อ (ไม่ระบุก็ได้)</option>
                      <option value="booking">สอบถามเรื่องการจองทริป</option>
                      <option value="payment">ปัญหาการชำระเงิน</option>
                      <option value="partnership">ร่วมเป็นพาร์ทเนอร์</option>
                      <option value="general">สอบถามทั่วไป / แนะนำบริการ</option>
                      <option value="other">อื่นๆ</option>
                    </select>
                  </div>

                  <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-2">
                      <label for="contact-name" class="text-sm font-bold text-text-dark ml-1">ชื่อ-นามสกุล</label>
                      <input id="contact-name" v-model="form.name" type="text" required placeholder="เช่น สมชาย ใจดี" autocomplete="name"
                        class="w-full px-6 py-4 bg-sand/30 border border-sand-dark/40 rounded-2xl focus:outline-none focus:border-primary focus:bg-white transition-colors" />
                    </div>
                    <div class="space-y-2">
                      <label for="contact-phone" class="text-sm font-bold text-text-dark ml-1">เบอร์โทรศัพท์ (ถ้ามี)</label>
                      <input id="contact-phone" v-model="form.phone" type="tel" placeholder="08x-xxx-xxxx" autocomplete="tel-national"
                        class="w-full px-6 py-4 bg-sand/30 border border-sand-dark/40 rounded-2xl focus:outline-none focus:border-primary focus:bg-white transition-colors" />
                    </div>
                  </div>

                  <div class="space-y-2">
                    <label for="contact-email" class="text-sm font-bold text-text-dark ml-1">อีเมลที่ใช้ติดต่อกลับ</label>
                    <input id="contact-email" v-model.trim="form.email" type="email" required placeholder="example@mail.com" autocomplete="email"
                      class="w-full px-6 py-4 bg-sand/30 border border-sand-dark/40 rounded-2xl focus:outline-none focus:border-primary focus:bg-white transition-colors" />
                  </div>

                  <!-- Partnership Details -->
                  <div v-if="form.subject === 'partnership'"
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

                  <div class="space-y-2">
                    <label for="contact-message" class="text-sm font-bold text-text-dark ml-1">รายละเอียด</label>
                    <textarea id="contact-message" v-model="form.message" rows="5" required
                      :placeholder="messagePlaceholder"
                      class="w-full px-6 py-4 bg-sand/30 border border-sand-dark/40 rounded-2xl focus:outline-none focus:border-primary focus:bg-white transition-colors resize-none"></textarea>
                    <p v-if="form.subject === 'booking' || form.subject === 'payment'" class="text-xs text-text-muted ml-1">
                      ถ้ามีเลขที่จอง (LLK-...) ใส่มาด้วยจะช่วยให้ตรวจสอบได้เร็วขึ้นครับ
                    </p>
                  </div>

                  <div class="pt-2">
                    <button type="submit" :disabled="submitting"
                      class="w-full py-4 bg-primary text-white rounded-2xl font-bold text-lg hover:bg-primary-mid active:scale-[0.98] transition-all disabled:opacity-70 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                      <span v-if="submitting" class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                      {{ submitting ? 'กำลังส่ง...' : 'ส่งข้อความ' }}
                    </button>

                    <p class="mt-4 text-xs text-text-muted text-center leading-relaxed">
                      ข้อมูลที่ส่งมาใช้เพื่อติดต่อกลับเท่านั้น · เรื่องด่วนแนะนำให้โทรหรือทัก LINE
                    </p>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Map — โหลด iframe ของ Google เมื่อกดเท่านั้น ไม่ลากผู้ใช้ไปให้ Google ทุกครั้งที่เปิดหน้า -->
    <section class="h-[360px] w-full bg-sand-dark overflow-hidden relative">
      <iframe v-if="mapLoaded"
        src="https://www.google.com/maps/embed?pb=!1m14!1m12!1m3!1d1142.796759501285!2d100.58578633819413!3d13.667300090214399!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!5e0!3m2!1sth!2sth!4v1775908057144!5m2!1sth!2sth"
        width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>

      <div v-else class="h-full flex flex-col items-center justify-center gap-4 px-6 text-center">
        <span class="material-symbols-rounded text-4xl text-primary">map</span>
        <p class="font-bold text-text-dark leading-snug">
          ถนนทางรถไฟเก่า แขวงบางนาใต้<br />เขตบางนา กรุงเทพมหานคร 10260
        </p>
        <div class="flex flex-wrap justify-center gap-3">
          <button type="button" @click="mapLoaded = true"
            class="bg-white text-text-dark px-6 py-3 rounded-full font-bold border border-sand-dark/40 hover:border-primary transition-colors">
            แสดงแผนที่
          </button>
          <a href="https://maps.google.com/?q=บางนา+กรุงเทพ" target="_blank" rel="noopener"
            class="bg-primary text-white px-6 py-3 rounded-full font-bold hover:bg-primary-mid transition-colors flex items-center gap-2">
            <span class="material-symbols-rounded text-[20px]">open_in_new</span>
            เปิดใน Google Maps
          </a>
        </div>
      </div>
    </section>

  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import api from '../lib/axios'
import { useAuthStore } from '../stores/auth'
import { useToast } from '../lib/toast'

const auth = useAuthStore()
const toast = useToast()

const submitting = ref(false)
const submitted = ref(false)
const mapLoaded = ref(false)

const form = reactive({
  name: '',
  phone: '',
  email: '',
  subject: '',
  message: '',
  forests_hiked: ''
})

// คำใบ้เปลี่ยนตามเรื่องที่เลือก เพื่อให้คนเขียนสิ่งที่ทีมงานต้องใช้จริงมาตั้งแต่แรก
const messagePlaceholder = computed(() => ({
  booking: 'เช่น สนใจทริปไหน วันที่เท่าไหร่ ไปกี่คน หรือมีอะไรที่อยากถามก่อนจอง',
  payment: 'เล่าสิ่งที่เกิดขึ้น เช่น โอนเมื่อไหร่ ยอดเท่าไหร่ และสถานะที่เห็นในระบบตอนนี้',
  partnership: 'เล่าประสบการณ์และสิ่งที่อยากร่วมงานกับเรา',
}[form.subject] || 'เขียนเรื่องที่อยากให้ช่วยได้เลยครับ'))

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

    await api.post('/contacts', formData)

    submitted.value = true
  } catch (error) {
    // 429 = ส่งถี่เกินไป (rate limiter 'contact') — บอกให้ชัดว่าให้รอ ไม่ใช่ "เกิดข้อผิดพลาด"
    const message = error.response?.status === 429
      ? 'ส่งข้อความถี่เกินไป รบกวนรอสักครู่แล้วลองใหม่อีกครั้งครับ'
      : error.response?.data?.message || 'ส่งข้อความไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'
    toast.error(message)
  } finally {
    submitting.value = false
  }
}

function resetForm() {
  form.subject = ''
  form.message = ''
  form.forests_hiked = ''
  imagePreviews.value.forEach((url) => URL.revokeObjectURL(url))
  images.value = []
  imagePreviews.value = []
  submitted.value = false
}

// คนที่ล็อกอินแล้วไม่ต้องพิมพ์ชื่อ/อีเมล/เบอร์ซ้ำ
onMounted(() => {
  if (!auth.isLoggedIn || !auth.user) return
  form.name = form.name || auth.user.name || ''
  form.email = form.email || auth.user.email || ''
  form.phone = form.phone || auth.user.phone || ''
})

onBeforeUnmount(() => {
  imagePreviews.value.forEach((url) => URL.revokeObjectURL(url))
})
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
