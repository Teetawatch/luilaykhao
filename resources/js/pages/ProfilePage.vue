<template>
  <div class="min-h-screen bg-[#f9f9f9] pb-32">
    <!-- Header -->
    <div class="bg-white border-b border-gray-100 pt-8 pb-16 px-4">
      <div class="max-w-2xl mx-auto flex flex-col items-center">
        <h1 class="text-2xl font-bold text-[#1a1c1c] mb-8" style="font-family:'Anuphan',sans-serif;">จัดการโปรไฟล์</h1>
        
        <!-- Avatar Upload -->
        <div class="relative group">
          <div class="w-32 h-32 rounded-3xl overflow-hidden bg-gray-100 border-4 border-white shadow-xl group-hover:opacity-90 transition-all">
            <img 
              :src="avatarPreview || auth.user?.avatar_url || '/images/default-avatar.png'" 
              alt="Profile" 
              class="w-full h-full object-cover"
            />
          </div>
          <label 
            for="avatar-upload" 
            class="absolute -bottom-2 -right-2 w-10 h-10 bg-[#006565] text-white rounded-2xl flex items-center justify-center cursor-pointer shadow-lg hover:scale-110 transition-transform border-4 border-white"
          >
            <i class="fa-solid fa-camera text-sm"></i>
          </label>
          <input 
            id="avatar-upload" 
            type="file" 
            class="hidden" 
            @change="handleAvatarChange" 
            accept="image/*"
          />
        </div>
        
        <p class="mt-4 text-sm text-[#3e4949] font-medium" style="font-family:'Anuphan',sans-serif;">{{ auth.user?.name }}</p>
        <p class="text-xs text-[#bdc9c8] mt-1">{{ auth.user?.email }}</p>
      </div>
    </div>

    <!-- Content -->
    <div class="max-w-2xl mx-auto px-4 -mt-8">
      <div class="space-y-6">
        <!-- Error/Success Alerts -->
        <div v-if="error" class="p-4 bg-red-50 border border-red-100 rounded-2xl flex items-center gap-3 animate-fade-in">
          <i class="fa-solid fa-circle-exclamation text-red-500"></i>
          <p class="text-red-700 text-sm font-medium">{{ error }}</p>
        </div>
        <div v-if="success" class="p-4 bg-green-50 border border-green-100 rounded-2xl flex items-center gap-3 animate-fade-in">
          <i class="fa-solid fa-circle-check text-green-500"></i>
          <p class="text-green-700 text-sm font-medium">{{ success }}</p>
        </div>

        <!-- Personal Information -->
        <div class="bg-white rounded-3xl p-6 shadow-sm border border-gray-100">
          <h3 class="text-sm font-bold text-[#1a1c1c] uppercase tracking-wider mb-6 flex items-center gap-2" style="font-family:'Anuphan',sans-serif;">
            <i class="fa-regular fa-user text-[#006565]"></i> ข้อมูลส่วนตัว
          </h3>
          
          <div class="space-y-4">
            <div class="grid grid-cols-12 gap-4">
              <div class="col-span-12 md:col-span-3 space-y-1.5">
                <label class="text-xs font-bold text-[#bdc9c8] uppercase ml-1">คำนำหน้า</label>
                <select 
                  v-model="form.title" 
                  class="w-full bg-[#fcfcfc] border border-gray-100 rounded-2xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                >
                  <option value="" disabled>เลือก...</option>
                  <option value="นาย">นาย</option>
                  <option value="นาง">นาง</option>
                  <option value="นางสาว">นางสาว</option>
                </select>
              </div>
              <div class="col-span-12 md:col-span-9 space-y-1.5">
                <label class="text-xs font-bold text-[#bdc9c8] uppercase ml-1">ชื่อ-นามสกุล</label>
                <input 
                  v-model="form.name" 
                  type="text" 
                  class="w-full bg-[#fcfcfc] border border-gray-100 rounded-2xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                  placeholder="ชื่อของคุณ"
                />
              </div>
            </div>

            <div class="space-y-1.5">
              <label class="text-xs font-bold text-[#bdc9c8] uppercase ml-1">เบอร์โทรศัพท์</label>
              <input 
                v-model="form.phone" 
                type="tel" 
                class="w-full bg-[#fcfcfc] border border-gray-100 rounded-2xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                placeholder="08X-XXX-XXXX"
              />
            </div>

            <div class="space-y-1.5">
              <label class="text-xs font-bold text-[#bdc9c8] uppercase ml-1">ชื่อเล่น</label>
              <input 
                v-model="form.nickname" 
                type="text" 
                class="w-full bg-[#fcfcfc] border border-gray-100 rounded-2xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                placeholder="ชื่อเล่นของคุณ"
              />
            </div>

            <div class="space-y-1.5">
              <label class="text-xs font-bold text-[#bdc9c8] uppercase ml-1">เลขที่บัตรประชาชน (13 หลัก)</label>
              <input 
                v-model="form.id_card" 
                type="text" 
                maxlength="13"
                @input="form.id_card = form.id_card.replace(/\D/g, '')"
                class="w-full bg-[#fcfcfc] border border-gray-100 rounded-2xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                placeholder="X-XXXX-XXXXX-XX-X"
              />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="space-y-1.5">
                <label class="text-xs font-bold text-[#bdc9c8] uppercase ml-1">กรุ๊ปเลือด</label>
                <select 
                  v-model="form.blood_group"
                  class="w-full bg-[#fcfcfc] border border-gray-100 rounded-2xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                >
                  <option value="">ไม่ระบุ</option>
                  <option value="A">A</option>
                  <option value="B">B</option>
                  <option value="O">O</option>
                  <option value="AB">AB</option>
                </select>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="space-y-1.5">
                <label class="text-xs font-bold text-[#bdc9c8] uppercase ml-1">ผู้ติดต่อฉุกเฉิน</label>
                <input 
                  v-model="form.emergency_contact" 
                  type="text" 
                  class="w-full bg-[#fcfcfc] border border-gray-100 rounded-2xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                  placeholder="ชื่อผู้ติดต่อ"
                />
              </div>
              <div class="space-y-1.5">
                <label class="text-xs font-bold text-[#bdc9c8] uppercase ml-1">เบอร์ฉุกเฉิน</label>
                <input 
                  v-model="form.emergency_phone" 
                  type="tel" 
                  class="w-full bg-[#fcfcfc] border border-gray-100 rounded-2xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                  placeholder="08X-XXX-XXXX"
                />
              </div>
            </div>

            <div class="space-y-1.5">
              <label class="text-xs font-bold text-[#bdc9c8] uppercase ml-1">การแพ้อาหาร / อื่นๆ</label>
              <input 
                v-model="form.allergies" 
                type="text" 
                class="w-full bg-[#fcfcfc] border border-gray-100 rounded-2xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                placeholder="เช่น แพ้อาหารทะเล, ไม่ทานเนื้อ"
              />
            </div>

            <div class="space-y-1.5">
              <label class="text-xs font-bold text-[#bdc9c8] uppercase ml-1">หมายเหตุสุขภาพ</label>
              <textarea 
                v-model="form.health_notes" 
                rows="2"
                class="w-full bg-[#fcfcfc] border border-gray-100 rounded-2xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none resize-none"
                placeholder="แพ้ยา, โรคประจำตัว ฯลฯ"
              ></textarea>
            </div>

            <div class="space-y-1.5 opacity-60">
              <label class="text-xs font-bold text-[#bdc9c8] uppercase ml-1">อีเมล (ไม่สามารถเปลี่ยนได้)</label>
              <input 
                :value="auth.user?.email" 
                type="email" 
                disabled
                class="w-full bg-gray-50 border border-gray-100 rounded-2xl px-4 py-3.5 text-sm outline-none cursor-not-allowed"
              />
            </div>
          </div>
        </div>

        <!-- Security -->
        <div class="bg-white rounded-3xl p-6 shadow-sm border border-gray-100">
          <h3 class="text-sm font-bold text-[#1a1c1c] uppercase tracking-wider mb-6 flex items-center gap-2" style="font-family:'Anuphan',sans-serif;">
            <i class="fa-solid fa-shield-halved text-[#006565]"></i> ความปลอดภัย
          </h3>
          
          <div class="space-y-4">
            <div class="space-y-1.5">
              <label class="text-xs font-bold text-[#bdc9c8] uppercase ml-1">รหัสผ่านใหม่ (ถ้าต้องการเปลี่ยน)</label>
              <input 
                v-model="form.password" 
                type="password" 
                class="w-full bg-[#fcfcfc] border border-gray-100 rounded-2xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                placeholder="••••••••"
              />
            </div>
            <div class="space-y-1.5">
              <label class="text-xs font-bold text-[#bdc9c8] uppercase ml-1">ยืนยันรหัสผ่านใหม่</label>
              <input 
                v-model="form.password_confirmation" 
                type="password" 
                class="w-full bg-[#fcfcfc] border border-gray-100 rounded-2xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                placeholder="••••••••"
              />
            </div>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex flex-col gap-3">
          <button 
            @click="handleSave"
            :disabled="saving"
            class="w-full bg-[#006565] text-white py-4 rounded-2xl font-bold shadow-lg shadow-[#006565]/20 hover:bg-[#004d4d] transition-all flex items-center justify-center gap-2 disabled:opacity-70"
          >
            <i v-if="saving" class="fa-solid fa-circle-notch fa-spin"></i>
            <span v-else>บันทึกการเปลี่ยนแปลง</span>
          </button>
          
          <button 
            @click="handleLogout"
            class="w-full bg-white text-red-500 border border-red-50 py-4 rounded-2xl font-bold hover:bg-red-50 transition-all flex items-center justify-center gap-2"
          >
            <i class="fa-solid fa-right-from-bracket"></i> ออกจากระบบ
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import api from '../lib/axios';

const auth = useAuthStore();
const router = useRouter();

const saving = ref(false);
const error = ref('');
const success = ref('');
const avatarFile = ref(null);
const avatarPreview = ref(null);

const form = ref({
  title: auth.user?.title || '',
  name: auth.user?.name || '',
  phone: auth.user?.phone || '',
  nickname: auth.user?.nickname || '',
  id_card: auth.user?.id_card || '',
  blood_group: auth.user?.blood_group || '',
  emergency_contact: auth.user?.emergency_contact || '',
  emergency_phone: auth.user?.emergency_phone || '',
  allergies: auth.user?.allergies || '',
  health_notes: auth.user?.health_notes || '',
  password: '',
  password_confirmation: '',
});

function handleAvatarChange(e) {
  const file = e.target.files[0];
  if (file) {
    avatarFile.value = file;
    const reader = new FileReader();
    reader.onload = (e) => {
      avatarPreview.value = e.target.result;
    };
    reader.readAsDataURL(file);
  }
}

async function handleSave() {
  error.value = '';
  success.value = '';
  saving.value = true;

  try {
    const formData = new FormData();
    formData.append('name', form.value.name);
    if (form.value.title) formData.append('title', form.value.title);
    if (form.value.phone) formData.append('phone', form.value.phone);
    if (form.value.nickname) formData.append('nickname', form.value.nickname);
    if (form.value.id_card) formData.append('id_card', form.value.id_card);
    if (form.value.blood_group) formData.append('blood_group', form.value.blood_group);
    if (form.value.emergency_contact) formData.append('emergency_contact', form.value.emergency_contact);
    if (form.value.emergency_phone) formData.append('emergency_phone', form.value.emergency_phone);
    if (form.value.allergies) formData.append('allergies', form.value.allergies);
    if (form.value.health_notes) formData.append('health_notes', form.value.health_notes);
    if (form.value.password) {
      formData.append('password', form.value.password);
      formData.append('password_confirmation', form.value.password_confirmation);
    }
    if (avatarFile.value) {
      formData.append('avatar', avatarFile.value);
    }

    const res = await api.post('/auth/profile', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });

    // Update store
    if (res.data.data) {
      auth.user = res.data.data;
      localStorage.setItem('auth_user', JSON.stringify(auth.user));
    }
    
    success.value = 'บันทึกข้อมูลเรียบร้อยแล้ว';
    form.value.password = '';
    form.value.password_confirmation = '';
    
    // Clear notifications after 3 seconds
    setTimeout(() => { success.value = ''; }, 3000);
  } catch (e) {
    const data = e?.response?.data;
    if (data?.errors) {
      const firstKey = Object.keys(data.errors)[0];
      error.value = data.errors[firstKey][0];
    } else {
      error.value = data?.message || 'เกิดข้อผิดพลาดในการบันทึกข้อมูล';
    }
  } finally {
    saving.value = false;
  }
}

async function handleLogout() {
  if (confirm('คุณต้องการออกจากระบบใช่หรือไม่?')) {
    await auth.logout();
    router.push('/login');
  }
}

onMounted(() => {
  if (!auth.isLoggedIn) {
    router.push('/login');
  }
});
</script>

<style scoped>
.animate-fade-in {
  animation: fadeIn 0.3s ease-out;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-5px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>
