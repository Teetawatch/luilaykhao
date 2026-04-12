<template>
  <div class="min-h-screen bg-[#f9f9f9] pb-32 font-['Anuphan']">

    <!-- Hero Banner Section -->
    <section class="relative mb-12 overflow-hidden h-48 md:h-64 flex items-end">
      <img
        class="absolute inset-0 w-full h-full object-cover"
        src="https://images.unsplash.com/photo-1505118380757-91f5f5632de0?w=1400&q=80"
        alt="banner"
      />
      <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent"></div>
      <div class="relative w-full px-6 md:px-8 pb-8 flex flex-col md:flex-row items-center md:items-end gap-6 max-w-7xl mx-auto">
        <!-- Avatar -->
        <div class="relative group">
          <div class="w-24 h-24 md:w-32 md:h-32 rounded-full border-4 border-white shadow-xl overflow-hidden bg-[#eeeeee]">
            <img
              :src="avatarPreview || auth.user?.avatar_url || '/images/default-avatar.png'"
              alt="Profile"
              class="w-full h-full object-cover"
            />
          </div>
          <label
            for="avatar-upload"
            class="absolute bottom-1 right-1 bg-[#006565] text-white p-2 rounded-full shadow-lg hover:scale-105 transition-transform cursor-pointer"
          >
            <span class="material-symbols-rounded text-sm" style="font-size:18px;">photo_camera</span>
          </label>
          <input id="avatar-upload" type="file" class="hidden" @change="handleAvatarChange" accept="image/*" />
        </div>
        <!-- Name & Tier -->
        <div class="text-center md:text-left text-white mb-2">
          <h1 class="text-3xl md:text-4xl font-bold mb-1 tracking-tight">
            {{ auth.user?.title ? auth.user.title + ' ' : '' }}{{ auth.user?.name }}
          </h1>
          <div class="flex items-center justify-center md:justify-start gap-2">
            <span
              v-if="loyaltyTierLabel"
              class="bg-[#006565]/20 backdrop-blur-md border border-white/20 px-4 py-1 rounded-full text-sm font-medium text-[#93f2f2]"
            >
              {{ loyaltyTierLabel }}
            </span>
            <span v-if="loyaltyTierLabel" class="material-symbols-rounded text-[#93f2f2] text-lg" style="font-variation-settings:'FILL' 1;font-size:20px;">verified</span>
          </div>
        </div>
      </div>
    </section>

    <!-- Main Layout -->
    <div class="max-w-7xl mx-auto px-4 md:px-8 grid grid-cols-1 lg:grid-cols-12 gap-10">

      <!-- Sidebar -->
      <aside class="lg:col-span-3 space-y-2">
        <nav class="sticky top-28 bg-[#f3f3f3]/50 backdrop-blur-sm p-4 rounded-2xl">
          <button
            v-for="tab in tabs"
            :key="tab.key"
            @click="activeTab = tab.key"
            class="w-full flex items-center gap-4 px-4 py-3 rounded-xl font-semibold transition-all text-left"
            :class="activeTab === tab.key
              ? 'bg-[#b4eae9] text-[#004f4f] shadow-sm'
              : 'text-[#3e4949] hover:bg-[#eeeeee]'"
          >
            <span class="material-symbols-rounded" style="font-size:22px;">{{ tab.icon }}</span>
            <span>{{ tab.label }}</span>
          </button>
        </nav>

        <!-- Quick Links -->
        <div class="pt-4 space-y-4">
          <h3 class="px-4 text-xs font-bold uppercase tracking-widest text-[#3e4949]/60">เมนูทางลัด</h3>
          <router-link
            to="/my-bookings"
            class="block bg-white p-5 rounded-2xl shadow-[0px_10px_30px_rgba(0,64,64,0.04)] hover:bg-[#f3f3f3] transition-colors cursor-pointer"
          >
            <div class="flex items-center justify-between mb-3">
              <span class="material-symbols-rounded text-[#006565]" style="font-size:22px;">confirmation_number</span>
              <span class="material-symbols-rounded text-[#6e7979]" style="font-size:18px;">chevron_right</span>
            </div>
            <p class="font-bold text-[#1a1c1c]">การจองของฉัน</p>
            <p class="text-sm text-[#3e4949]/70">ดูประวัติและแผนการเดินทาง</p>
          </router-link>
          <router-link
            to="/loyalty"
            class="block bg-white p-5 rounded-2xl shadow-[0px_10px_30px_rgba(0,64,64,0.04)] hover:bg-[#f3f3f3] transition-colors cursor-pointer"
          >
            <div class="flex items-center justify-between mb-3">
              <span class="material-symbols-rounded text-[#9e380d]" style="font-size:22px;">card_membership</span>
              <span class="material-symbols-rounded text-[#6e7979]" style="font-size:18px;">chevron_right</span>
            </div>
            <p class="font-bold text-[#1a1c1c]">แต้มสะสม</p>
            <p class="text-sm text-[#3e4949]/70">{{ loyaltyPoints !== null ? loyaltyPoints.toLocaleString() + ' แต้ม' : 'ดูคะแนนสะสม' }}</p>
          </router-link>
        </div>
      </aside>

      <!-- Main Content -->
      <div class="lg:col-span-9">

        <!-- Alerts -->
        <div v-if="error" class="mb-6 p-4 bg-red-50 border border-red-100 rounded-2xl flex items-center gap-3 animate-fade-in">
          <span class="material-symbols-rounded text-red-500" style="font-size:20px;">error</span>
          <p class="text-red-700 text-sm font-medium">{{ error }}</p>
        </div>
        <div v-if="success" class="mb-6 p-4 bg-green-50 border border-green-100 rounded-2xl flex items-center gap-3 animate-fade-in">
          <span class="material-symbols-rounded text-green-500" style="font-size:20px;">check_circle</span>
          <p class="text-green-700 text-sm font-medium">{{ success }}</p>
        </div>

        <!-- ─── TAB: ข้อมูลส่วนตัว ─── -->
        <div v-if="activeTab === 'info'" class="bg-white p-8 md:p-12 rounded-2xl shadow-[0px_20px_60px_rgba(0,64,64,0.05)] border border-[#bdc9c8]/10">
          <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-10 pb-6 border-b border-[#eeeeee]">
            <div>
              <h2 class="text-2xl font-bold text-[#1a1c1c]">ข้อมูลส่วนตัว</h2>
              <p class="text-[#3e4949]">จัดการข้อมูลพื้นฐานและการตั้งค่าบัญชีของคุณ</p>
            </div>
            <button
              v-if="!editMode"
              @click="editMode = true"
              class="bg-[#006565] text-white px-8 py-3 rounded-xl font-semibold hover:opacity-90 transition-opacity flex items-center gap-2 self-start md:self-center shadow-lg shadow-[#006565]/20"
            >
              <span class="material-symbols-rounded text-lg" style="font-size:20px;">edit</span>
              แก้ไขข้อมูล
            </button>
          </div>

          <!-- View Mode -->
          <div v-if="!editMode" class="grid grid-cols-1 md:grid-cols-2 gap-8">
            <div class="space-y-2">
              <label class="block text-sm font-bold text-[#3e4949]/80 ml-1">คำนำหน้า</label>
              <div class="bg-[#f3f3f3]/50 px-5 py-4 rounded-xl text-[#1a1c1c] font-medium">
                {{ auth.user?.title || '—' }}
              </div>
            </div>
            <div class="space-y-2">
              <label class="block text-sm font-bold text-[#3e4949]/80 ml-1">ชื่อ-นามสกุล</label>
              <div class="bg-[#f3f3f3]/50 px-5 py-4 rounded-xl text-[#1a1c1c] font-medium">
                {{ auth.user?.name || '—' }}
              </div>
            </div>
            <div class="space-y-2">
              <label class="block text-sm font-bold text-[#3e4949]/80 ml-1">ชื่อเล่น</label>
              <div class="bg-[#f3f3f3]/50 px-5 py-4 rounded-xl text-[#1a1c1c] font-medium">
                {{ auth.user?.nickname || '—' }}
              </div>
            </div>
            <div class="space-y-2">
              <label class="block text-sm font-bold text-[#3e4949]/80 ml-1">เบอร์โทรศัพท์</label>
              <div class="bg-[#f3f3f3]/50 px-5 py-4 rounded-xl text-[#1a1c1c] font-medium">
                {{ auth.user?.phone || '—' }}
              </div>
            </div>
            <div class="space-y-2">
              <label class="block text-sm font-bold text-[#3e4949]/80 ml-1">อีเมล</label>
              <div class="bg-[#f3f3f3]/50 px-5 py-4 rounded-xl text-[#1a1c1c] font-medium flex items-center justify-between">
                <span>{{ auth.user?.email || '—' }}</span>
                <span class="material-symbols-rounded text-[#006565] text-lg" style="font-size:20px;">verified_user</span>
              </div>
            </div>
            <div class="space-y-2">
              <label class="block text-sm font-bold text-[#3e4949]/80 ml-1">กรุ๊ปเลือด</label>
              <div class="bg-[#f3f3f3]/50 px-5 py-4 rounded-xl text-[#1a1c1c] font-medium">
                {{ auth.user?.blood_group || '—' }}
              </div>
            </div>
            <div class="space-y-2">
              <label class="block text-sm font-bold text-[#3e4949]/80 ml-1">ผู้ติดต่อฉุกเฉิน</label>
              <div class="bg-[#f3f3f3]/50 px-5 py-4 rounded-xl text-[#1a1c1c] font-medium">
                {{ auth.user?.emergency_contact || '—' }}
              </div>
            </div>
            <div class="space-y-2">
              <label class="block text-sm font-bold text-[#3e4949]/80 ml-1">เบอร์ฉุกเฉิน</label>
              <div class="bg-[#f3f3f3]/50 px-5 py-4 rounded-xl text-[#1a1c1c] font-medium">
                {{ auth.user?.emergency_phone || '—' }}
              </div>
            </div>
            <div v-if="auth.user?.id_card" class="space-y-2">
              <label class="block text-sm font-bold text-[#3e4949]/80 ml-1">เลขบัตรประชาชน</label>
              <div class="bg-[#f3f3f3]/50 px-5 py-4 rounded-xl text-[#1a1c1c] font-medium">
                {{ maskedIdCard }}
              </div>
            </div>
            <div v-if="auth.user?.allergies" class="space-y-2">
              <label class="block text-sm font-bold text-[#3e4949]/80 ml-1">การแพ้อาหาร / อื่นๆ</label>
              <div class="bg-[#f3f3f3]/50 px-5 py-4 rounded-xl text-[#1a1c1c] font-medium">
                {{ auth.user.allergies }}
              </div>
            </div>
            <div v-if="auth.user?.health_notes" class="md:col-span-2 space-y-2">
              <label class="block text-sm font-bold text-[#3e4949]/80 ml-1">หมายเหตุสุขภาพ</label>
              <div class="bg-[#f3f3f3]/50 px-5 py-4 rounded-xl text-[#1a1c1c] font-medium leading-relaxed">
                {{ auth.user.health_notes }}
              </div>
            </div>
          </div>

          <!-- Edit Mode -->
          <div v-else class="space-y-5">
            <div class="grid grid-cols-12 gap-4">
              <div class="col-span-12 md:col-span-3 space-y-1.5">
                <label class="text-sm font-bold text-[#3e4949]/80 ml-1">คำนำหน้า</label>
                <select
                  v-model="form.title"
                  class="w-full bg-[#f3f3f3]/50 border border-[#eeeeee] rounded-xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                >
                  <option value="" disabled>เลือก...</option>
                  <option value="นาย">นาย</option>
                  <option value="นาง">นาง</option>
                  <option value="นางสาว">นางสาว</option>
                </select>
              </div>
              <div class="col-span-12 md:col-span-9 space-y-1.5">
                <label class="text-sm font-bold text-[#3e4949]/80 ml-1">ชื่อ-นามสกุล</label>
                <input
                  v-model="form.name"
                  type="text"
                  class="w-full bg-[#f3f3f3]/50 border border-[#eeeeee] rounded-xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                  placeholder="ชื่อของคุณ"
                />
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
              <div class="space-y-1.5">
                <label class="text-sm font-bold text-[#3e4949]/80 ml-1">ชื่อเล่น</label>
                <input
                  v-model="form.nickname"
                  type="text"
                  class="w-full bg-[#f3f3f3]/50 border border-[#eeeeee] rounded-xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                  placeholder="ชื่อเล่นของคุณ"
                />
              </div>
              <div class="space-y-1.5">
                <label class="text-sm font-bold text-[#3e4949]/80 ml-1">เบอร์โทรศัพท์</label>
                <input
                  v-model="form.phone"
                  type="tel"
                  class="w-full bg-[#f3f3f3]/50 border border-[#eeeeee] rounded-xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                  placeholder="08X-XXX-XXXX"
                />
              </div>
              <div class="space-y-1.5">
                <label class="text-sm font-bold text-[#3e4949]/80 ml-1">กรุ๊ปเลือด</label>
                <select
                  v-model="form.blood_group"
                  class="w-full bg-[#f3f3f3]/50 border border-[#eeeeee] rounded-xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                >
                  <option value="">ไม่ระบุ</option>
                  <option value="A">A</option>
                  <option value="B">B</option>
                  <option value="O">O</option>
                  <option value="AB">AB</option>
                </select>
              </div>
              <div class="space-y-1.5">
                <label class="text-sm font-bold text-[#3e4949]/80 ml-1">เลขที่บัตรประชาชน (13 หลัก)</label>
                <input
                  v-model="form.id_card"
                  type="text"
                  maxlength="13"
                  @input="form.id_card = form.id_card.replace(/\D/g, '')"
                  class="w-full bg-[#f3f3f3]/50 border border-[#eeeeee] rounded-xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                  placeholder="X-XXXX-XXXXX-XX-X"
                />
              </div>
              <div class="space-y-1.5">
                <label class="text-sm font-bold text-[#3e4949]/80 ml-1">ผู้ติดต่อฉุกเฉิน</label>
                <input
                  v-model="form.emergency_contact"
                  type="text"
                  class="w-full bg-[#f3f3f3]/50 border border-[#eeeeee] rounded-xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                  placeholder="ชื่อผู้ติดต่อ"
                />
              </div>
              <div class="space-y-1.5">
                <label class="text-sm font-bold text-[#3e4949]/80 ml-1">เบอร์ฉุกเฉิน</label>
                <input
                  v-model="form.emergency_phone"
                  type="tel"
                  class="w-full bg-[#f3f3f3]/50 border border-[#eeeeee] rounded-xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                  placeholder="08X-XXX-XXXX"
                />
              </div>
            </div>

            <div class="space-y-1.5">
              <label class="text-sm font-bold text-[#3e4949]/80 ml-1">การแพ้อาหาร / อื่นๆ</label>
              <input
                v-model="form.allergies"
                type="text"
                class="w-full bg-[#f3f3f3]/50 border border-[#eeeeee] rounded-xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                placeholder="เช่น แพ้อาหารทะเล, ไม่ทานเนื้อ"
              />
            </div>

            <div class="space-y-1.5">
              <label class="text-sm font-bold text-[#3e4949]/80 ml-1">หมายเหตุสุขภาพ</label>
              <textarea
                v-model="form.health_notes"
                rows="2"
                class="w-full bg-[#f3f3f3]/50 border border-[#eeeeee] rounded-xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none resize-none"
                placeholder="แพ้ยา, โรคประจำตัว ฯลฯ"
              ></textarea>
            </div>

            <div class="space-y-1.5 opacity-60">
              <label class="text-sm font-bold text-[#3e4949]/80 ml-1">อีเมล (ไม่สามารถเปลี่ยนได้)</label>
              <input
                :value="auth.user?.email"
                type="email"
                disabled
                class="w-full bg-[#eeeeee] border border-[#eeeeee] rounded-xl px-4 py-3.5 text-sm outline-none cursor-not-allowed"
              />
            </div>

            <!-- Edit Actions -->
            <div class="flex gap-3 pt-2">
              <button
                @click="handleSave"
                :disabled="saving"
                class="flex-1 bg-[#006565] text-white py-3.5 rounded-xl font-bold shadow-lg shadow-[#006565]/20 hover:bg-[#004d4d] transition-all flex items-center justify-center gap-2 disabled:opacity-70"
              >
                <span v-if="saving" class="material-symbols-rounded animate-spin" style="font-size:18px;">refresh</span>
                <span v-else>บันทึกการเปลี่ยนแปลง</span>
              </button>
              <button
                @click="cancelEdit"
                class="px-6 py-3.5 rounded-xl font-semibold border border-[#eeeeee] text-[#3e4949] hover:bg-[#f3f3f3] transition-all"
              >
                ยกเลิก
              </button>
            </div>
          </div>

          <!-- Loyalty Banner -->
          <div class="mt-12 p-8 rounded-2xl bg-gradient-to-br from-[#006565] to-[#008080] text-white overflow-hidden relative group">
            <div class="relative z-10 flex flex-col md:flex-row items-center gap-8">
              <div class="bg-white/10 backdrop-blur-xl p-4 rounded-2xl border border-white/20">
                <span class="material-symbols-rounded text-4xl" style="font-size:40px;">card_membership</span>
              </div>
              <div class="flex-1 text-center md:text-left">
                <h3 class="text-xl font-bold mb-1">สะสมแต้มพรีเมียม</h3>
                <p class="opacity-90 text-sm">
                  <template v-if="loyaltyPoints !== null">
                    คุณมีคะแนนสะสมทั้งหมด {{ loyaltyPoints.toLocaleString() }} แต้ม
                    <span v-if="loyaltyNextTier"> · อีก {{ loyaltyNextTier.points_needed.toLocaleString() }} แต้ม เพื่อเลื่อนระดับเป็น {{ loyaltyNextTier.tier }}</span>
                  </template>
                  <template v-else>สะสมแต้มจากทุกการจองเดินทาง</template>
                </p>
              </div>
              <router-link
                to="/loyalty"
                class="bg-white text-[#006565] px-6 py-2 rounded-xl font-bold hover:opacity-90 transition-colors shrink-0"
              >
                ดูแต้มสะสม
              </router-link>
            </div>
            <div class="absolute -right-12 -top-12 w-48 h-48 bg-white/5 rounded-full blur-3xl group-hover:bg-white/10 transition-colors"></div>
          </div>
        </div>

        <!-- ─── TAB: ความปลอดภัย ─── -->
        <div v-if="activeTab === 'security'" class="bg-white p-8 md:p-12 rounded-2xl shadow-[0px_20px_60px_rgba(0,64,64,0.05)] border border-[#bdc9c8]/10">
          <div class="mb-10 pb-6 border-b border-[#eeeeee]">
            <h2 class="text-2xl font-bold text-[#1a1c1c]">ความปลอดภัย</h2>
            <p class="text-[#3e4949]">เปลี่ยนรหัสผ่านบัญชีของคุณ</p>
          </div>

          <div class="max-w-md space-y-5">
            <div class="space-y-1.5">
              <label class="text-sm font-bold text-[#3e4949]/80 ml-1">รหัสผ่านใหม่ (ถ้าต้องการเปลี่ยน)</label>
              <input
                v-model="form.password"
                type="password"
                class="w-full bg-[#f3f3f3]/50 border border-[#eeeeee] rounded-xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                placeholder="••••••••"
              />
            </div>
            <div class="space-y-1.5">
              <label class="text-sm font-bold text-[#3e4949]/80 ml-1">ยืนยันรหัสผ่านใหม่</label>
              <input
                v-model="form.password_confirmation"
                type="password"
                class="w-full bg-[#f3f3f3]/50 border border-[#eeeeee] rounded-xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-[#006565]/10 focus:border-[#006565] transition-all outline-none"
                placeholder="••••••••"
              />
            </div>
            <button
              @click="handleSave"
              :disabled="saving"
              class="w-full bg-[#006565] text-white py-3.5 rounded-xl font-bold shadow-lg shadow-[#006565]/20 hover:bg-[#004d4d] transition-all flex items-center justify-center gap-2 disabled:opacity-70"
            >
              <span v-if="saving" class="material-symbols-rounded animate-spin" style="font-size:18px;">refresh</span>
              <span v-else>บันทึกรหัสผ่านใหม่</span>
            </button>
          </div>
        </div>

        <!-- ─── TAB: ออกจากระบบ ─── -->
        <div v-if="activeTab === 'logout'" class="bg-white p-8 md:p-12 rounded-2xl shadow-[0px_20px_60px_rgba(0,64,64,0.05)] border border-[#bdc9c8]/10">
          <div class="mb-10 pb-6 border-b border-[#eeeeee]">
            <h2 class="text-2xl font-bold text-[#1a1c1c]">ออกจากระบบ</h2>
            <p class="text-[#3e4949]">คุณกำลังล็อกอินในฐานะ {{ auth.user?.email }}</p>
          </div>
          <button
            @click="handleLogout"
            class="bg-white text-red-500 border border-red-100 px-8 py-3.5 rounded-xl font-bold hover:bg-red-50 transition-all flex items-center gap-2"
          >
            <span class="material-symbols-rounded" style="font-size:20px;">logout</span>
            ออกจากระบบ
          </button>
        </div>

      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import api from '../lib/axios';

const auth = useAuthStore();
const router = useRouter();

const saving = ref(false);
const editMode = ref(false);
const activeTab = ref('info');

const tabs = [
  { key: 'info',     label: 'ข้อมูลส่วนตัว', icon: 'person'   },
  { key: 'security', label: 'ความปลอดภัย',   icon: 'security' },
  { key: 'logout',   label: 'ออกจากระบบ',    icon: 'logout'   },
];

const loyaltyPoints   = ref(null);
const loyaltyTierLabel = ref('');
const loyaltyNextTier  = ref(null);

const maskedIdCard = computed(() => {
  const id = auth.user?.id_card || '';
  if (id.length < 4) return id;
  return id.slice(0, 1) + '-XXXX-XXXXX-' + id.slice(-2, -1) + '-' + id.slice(-1);
});
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

function cancelEdit() {
  editMode.value = false;
  form.value.title             = auth.user?.title             || '';
  form.value.name              = auth.user?.name              || '';
  form.value.phone             = auth.user?.phone             || '';
  form.value.nickname          = auth.user?.nickname          || '';
  form.value.id_card           = auth.user?.id_card           || '';
  form.value.blood_group       = auth.user?.blood_group       || '';
  form.value.emergency_contact = auth.user?.emergency_contact || '';
  form.value.emergency_phone   = auth.user?.emergency_phone   || '';
  form.value.allergies         = auth.user?.allergies         || '';
  form.value.health_notes      = auth.user?.health_notes      || '';
}

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
    editMode.value = false;
    
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

async function fetchLoyalty() {
  try {
    const res = await api.get('/loyalty/account');
    const account = res.data?.data;
    if (account) {
      loyaltyPoints.value    = account.points   ?? null;
      loyaltyTierLabel.value = account.tier_label || '';
      loyaltyNextTier.value  = account.next_tier  || null;
    }
  } catch {}
}

onMounted(() => {
  if (!auth.isLoggedIn) {
    router.push('/login');
    return;
  }
  fetchLoyalty();
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
