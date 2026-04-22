<template>
  <div class="min-h-screen bg-[var(--color-sand)] pb-32 font-anuphan">

    <!-- Hero Banner Section -->
    <section class="relative mb-8 overflow-hidden h-52 sm:h-64 md:h-72 flex items-end bg-[var(--color-primary)]">
      <img
        class="absolute inset-0 w-full h-full object-cover mix-blend-overlay opacity-30 grayscale"
        src="https://images.unsplash.com/photo-1505118380757-91f5f5632de0?w=1400&q=80"
        alt="banner"
      />
      <!-- Gradient Overlay -->
      <div class="absolute inset-0 bg-gradient-to-t from-[var(--color-primary)] via-transparent to-transparent opacity-60"></div>

      <div class="relative w-full px-4 sm:px-6 md:px-8 pb-8 md:pb-10 flex flex-col sm:flex-row items-center sm:items-end gap-6 sm:gap-8 max-w-7xl mx-auto z-10">
        <!-- Avatar -->
        <div class="relative group shrink-0">
          <div class="w-28 h-28 sm:w-32 sm:h-32 md:w-40 md:h-40 rounded-[2.5rem] border-4 border-white shadow-2xl overflow-hidden bg-white group-hover:scale-[1.02] transition-transform duration-500">
            <img
              :src="avatarPreview || auth.user?.avatar_url || '/images/default-avatar.png'"
              alt="Profile"
              class="w-full h-full object-cover"
            />
          </div>
          <label
            for="avatar-upload"
            class="absolute -bottom-2 -right-2 bg-[var(--color-accent)] text-white p-2.5 md:p-3 rounded-2xl shadow-xl hover:bg-[var(--color-primary)] transition-all cursor-pointer ring-4 ring-white hover:rotate-12 active:scale-90"
          >
            <span class="material-symbols-rounded text-[20px] md:text-[22px]">photo_camera</span>
          </label>
          <input id="avatar-upload" type="file" class="hidden" @change="handleAvatarChange" accept="image/*" />
        </div>

        <!-- Name & Identity -->
        <div class="text-center sm:text-left text-white mb-2 min-w-0 flex-1">
          <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-3 mb-3">
            <h1 class="text-3xl sm:text-4xl md:text-5xl font-black tracking-tight truncate leading-tight">
              {{ auth.user?.title ? auth.user.title + ' ' : '' }}{{ auth.user?.name }}
            </h1>
            <div class="flex items-center justify-center sm:justify-start gap-2">
              <span
                v-if="loyaltyTierLabel"
                class="bg-[var(--color-accent)] text-white px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest shadow-lg shadow-[var(--color-accent)]/20"
              >
                {{ loyaltyTierLabel }}
              </span>
              <span v-if="loyaltyTierLabel" class="material-symbols-rounded text-[var(--color-accent-light)] text-[24px]" style="font-variation-settings:'FILL' 1">verified</span>
            </div>
          </div>

          <!-- Secondary Info -->
          <div class="flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-6 text-white/90 text-sm font-medium mb-6">
            <div class="flex items-center justify-center sm:justify-start gap-2 group cursor-default">
              <div class="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center group-hover:bg-white/20 transition-colors">
                <span class="material-symbols-rounded text-[18px]">mail</span>
              </div>
              <span class="truncate">{{ auth.user?.email }}</span>
            </div>
            <div v-if="auth.user?.phone" class="flex items-center justify-center sm:justify-start gap-2 group cursor-default">
              <div class="w-8 h-8 rounded-full bg-white/10 flex items-center justify-center group-hover:bg-white/20 transition-colors">
                <span class="material-symbols-rounded text-[18px]">call</span>
              </div>
              <span>{{ auth.user?.phone }}</span>
            </div>
          </div>

          <!-- Meta Info Badges -->
          <div class="flex flex-wrap items-center justify-center sm:justify-start gap-3">
            <div class="bg-white/10 backdrop-blur-md border border-white/20 px-4 py-2 rounded-2xl flex items-center gap-3 shadow-sm group hover:bg-white/15 transition-colors">
              <span class="material-symbols-rounded text-[18px] text-[var(--color-accent-light)]">calendar_today</span>
              <div class="text-left">
                <p class="text-[9px] font-black uppercase tracking-widest opacity-60 leading-none mb-0.5">สมาชิกตั้งแต่</p>
                <p class="text-xs font-bold">{{ formatJoinDate(auth.user?.created_at) }}</p>
              </div>
            </div>
            <div class="bg-white/10 backdrop-blur-md border border-white/20 px-4 py-2 rounded-2xl flex items-center gap-3 shadow-sm group hover:bg-white/15 transition-colors">
              <span class="material-symbols-rounded text-[18px] text-[var(--color-accent-light)]">confirmation_number</span>
              <div class="text-left">
                <p class="text-[9px] font-black uppercase tracking-widest opacity-60 leading-none mb-0.5">จองแล้ว</p>
                <p class="text-xs font-bold">{{ auth.user?.bookings_count || 0 }} ครั้ง</p>
              </div>
            </div>
            <div :class="providerBadge.class" class="px-4 py-2 rounded-2xl flex items-center gap-2 shadow-sm border opacity-90">
              <span class="scale-90" v-html="providerBadge.icon"></span>
              <span class="text-[10px] font-black uppercase tracking-widest">{{ providerBadge.label }}</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Main Layout -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 md:px-8">

      <!-- Mobile Tab Bar -->
      <div class="flex lg:hidden overflow-x-auto gap-2 pb-2 mb-6 scrollbar-none">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          @click="activeTab = tab.key"
          class="flex items-center gap-2 px-4 py-2.5 rounded-full font-bold transition-all whitespace-nowrap text-sm shrink-0"
          :class="activeTab === tab.key
            ? 'bg-[var(--color-primary)] text-white shadow-md'
            : 'bg-white text-[var(--color-text-mid)] border border-gray-200'"
        >
          <span class="material-symbols-rounded text-[18px]" :style="activeTab === tab.key ? 'font-variation-settings:\'FILL\' 1' : ''">{{ tab.icon }}</span>
          <span>{{ tab.label }}</span>
        </button>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-10">

      <!-- Sidebar (desktop only) -->
      <aside class="hidden lg:block lg:col-span-3 space-y-8">
        <nav class="sticky top-28 bg-white p-4 rounded-[2rem] shadow-[0_8px_30px_rgba(0,0,0,0.03)] border border-gray-100 overflow-hidden">
          <button
            v-for="tab in tabs"
            :key="tab.key"
            @click="activeTab = tab.key"
            class="w-full flex items-center gap-4 px-5 py-4 rounded-2xl font-bold transition-all text-left mb-1.5 last:mb-0 relative group overflow-hidden"
            :class="activeTab === tab.key
              ? 'bg-[var(--color-primary)] text-white shadow-lg shadow-[var(--color-primary)]/20'
              : 'text-[var(--color-text-mid)] hover:bg-[var(--color-sand)] hover:text-[var(--color-primary)]'"
          >
            <!-- Left indicator -->
            <div 
              v-if="activeTab === tab.key" 
              class="absolute left-0 top-1/2 -translate-y-1/2 w-1.5 h-8 bg-[var(--color-accent)] rounded-r-full animate-in slide-in-from-left duration-300"
            ></div>
            
            <span class="material-symbols-rounded text-[24px] relative z-10" :style="activeTab === tab.key ? 'font-variation-settings:\'FILL\' 1' : ''">{{ tab.icon }}</span>
            <span class="relative z-10">{{ tab.label }}</span>
            
            <!-- Hover effect -->
            <div class="absolute inset-0 bg-white/10 opacity-0 group-hover:opacity-100 transition-opacity"></div>
          </button>
        </nav>

        <!-- Quick Links -->
        <div class="pt-2 space-y-5">
          <h3 class="px-6 text-[11px] font-black uppercase tracking-[0.2em] text-[var(--color-text-muted)] opacity-70">เมนูทางลัด</h3>
          
          <router-link
            to="/my-bookings"
            class="block bg-white p-7 rounded-[2rem] shadow-[0_8px_30px_rgba(0,0,0,0.03)] border border-gray-100 hover:border-[var(--color-primary)] hover:shadow-[0_20px_40px_-10px_rgba(13,43,30,0.1)] hover:-translate-y-1 transition-all duration-300 cursor-pointer group"
          >
            <div class="flex items-center justify-between mb-5">
              <div class="w-14 h-14 rounded-2xl bg-[var(--color-sand)] flex items-center justify-center text-[var(--color-primary)] group-hover:bg-[var(--color-primary)] group-hover:text-white transition-all duration-500 group-hover:rotate-6">
                <span class="material-symbols-rounded text-[28px]">confirmation_number</span>
              </div>
              <div class="w-8 h-8 rounded-full bg-gray-50 flex items-center justify-center group-hover:bg-[var(--color-primary)]/10 transition-colors">
                <span class="material-symbols-rounded text-gray-300 group-hover:text-[var(--color-primary)] transition-colors text-[20px]">arrow_forward</span>
              </div>
            </div>
            <p class="font-black text-[var(--color-text-dark)] text-xl mb-1.5 tracking-tight">การจองของฉัน</p>
            <p class="text-xs font-bold text-[var(--color-text-muted)] opacity-80">ดูประวัติและแผนการเดินทาง</p>
          </router-link>

          <router-link
            to="/loyalty"
            class="block bg-white p-7 rounded-[2rem] shadow-[0_8px_30px_rgba(0,0,0,0.03)] border border-gray-100 hover:border-[var(--color-gold)] hover:shadow-[0_20px_40px_-10px_rgba(184,134,11,0.1)] hover:-translate-y-1 transition-all duration-300 cursor-pointer group"
          >
            <div class="flex items-center justify-between mb-5">
              <div class="w-14 h-14 rounded-2xl bg-[var(--color-sand)] flex items-center justify-center text-[var(--color-gold)] group-hover:bg-[var(--color-gold)] group-hover:text-white transition-all duration-500 group-hover:-rotate-6">
                <span class="material-symbols-rounded text-[28px]">card_membership</span>
              </div>
              <div class="w-8 h-8 rounded-full bg-gray-50 flex items-center justify-center group-hover:bg-[var(--color-gold)]/10 transition-colors">
                <span class="material-symbols-rounded text-gray-300 group-hover:text-[var(--color-gold)] transition-colors text-[20px]">arrow_forward</span>
              </div>
            </div>
            <p class="font-black text-[var(--color-text-dark)] text-xl mb-1.5 tracking-tight">แต้มสะสม</p>
            <p class="text-xs font-bold text-[var(--color-text-muted)] opacity-80">{{ loyaltyPoints !== null ? loyaltyPoints.toLocaleString() + ' แต้ม' : 'ดูคะแนนสะสม' }}</p>
          </router-link>
        </div>
      </aside>

      <!-- Main Content -->
      <div class="lg:col-span-9 min-w-0">

        <!-- Alerts -->
        <div v-if="error" class="mb-6 p-4 bg-red-50 border border-red-100 rounded-[1rem] flex items-center gap-3 animate-fade-in shadow-sm">
          <span class="material-symbols-rounded text-red-500 text-[24px]" style="font-variation-settings:'FILL' 1">error</span>
          <p class="text-red-700 text-sm font-bold">{{ error }}</p>
        </div>
        <div v-if="success" class="mb-6 p-4 bg-green-50 border border-green-100 rounded-[1rem] flex items-center gap-3 animate-fade-in shadow-sm">
          <span class="material-symbols-rounded text-green-500 text-[24px]" style="font-variation-settings:'FILL' 1">check_circle</span>
          <p class="text-green-700 text-sm font-bold">{{ success }}</p>
        </div>

        <!-- ─── TAB: ข้อมูลส่วนตัว ─── -->
        <div v-if="activeTab === 'info'" class="bg-white p-6 md:p-10 rounded-[2.5rem] shadow-[0_20px_50px_rgba(0,0,0,0.04)] border border-gray-100 relative overflow-hidden">
          
          <!-- Tab Header -->
          <div class="flex flex-col md:flex-row md:items-center justify-between gap-6 mb-12 pb-8 border-b border-gray-100">
            <div>
              <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 rounded-xl bg-[var(--color-primary)]/10 flex items-center justify-center text-[var(--color-primary)]">
                  <span class="material-symbols-rounded text-[24px]">person_celebrate</span>
                </div>
                <h2 class="text-3xl font-black text-[var(--color-text-dark)] tracking-tight">ข้อมูลส่วนตัว</h2>
              </div>
              <p class="text-[var(--color-text-muted)] font-medium ml-13">จัดการข้อมูลพื้นฐานและการตั้งค่าบัญชีของคุณให้เป็นปัจจุบัน</p>
            </div>
            
            <div class="flex items-center gap-3">
              <button
                v-if="!editMode"
                @click="editMode = true"
                class="bg-[var(--color-primary)] text-white px-8 py-4 rounded-2xl font-bold hover:bg-[var(--color-accent)] transition-all flex items-center gap-2 shadow-lg shadow-[var(--color-primary)]/20 hover:-translate-y-1 active:scale-95"
              >
                <span class="material-symbols-rounded text-[20px]">edit</span>
                แก้ไขข้อมูล
              </button>
              <template v-else>
                <button
                  @click="handleSave"
                  :disabled="saving"
                  class="bg-[var(--color-primary)] text-white px-8 py-4 rounded-2xl font-bold hover:bg-[var(--color-accent)] transition-all flex items-center gap-2 shadow-lg shadow-[var(--color-primary)]/20 hover:-translate-y-1 active:scale-95 disabled:opacity-50"
                >
                  <span v-if="saving" class="material-symbols-rounded animate-spin text-[20px]">refresh</span>
                  <span v-else class="material-symbols-rounded text-[20px]">save</span>
                  บันทึก
                </button>
                <button
                  @click="cancelEdit"
                  class="bg-[var(--color-sand)] text-[var(--color-text-dark)] px-6 py-4 rounded-2xl font-bold hover:bg-gray-200 transition-all flex items-center gap-2 hover:-translate-y-1 active:scale-95"
                >
                  ยกเลิก
                </button>
              </template>
            </div>
          </div>

          <div class="space-y-12">
            <!-- Section: ข้อมูลพื้นฐาน -->
            <div class="space-y-8">
              <div class="flex items-center gap-4">
                <div class="h-px flex-1 bg-gray-100"></div>
                <h3 class="text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-[0.2em] px-4 py-1.5 bg-[var(--color-sand)] rounded-full border border-gray-100">ข้อมูลพื้นฐาน</h3>
                <div class="h-px flex-1 bg-gray-100"></div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-12 gap-6">
                <!-- Title & Name -->
                <div class="md:col-span-3 space-y-2.5">
                  <label class="block text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-widest ml-1 opacity-70">คำนำหน้า</label>
                  <template v-if="!editMode">
                    <div class="bg-[var(--color-sand)]/50 px-5 py-4 rounded-2xl text-[var(--color-text-dark)] font-bold text-sm border border-transparent">
                      {{ auth.user?.title || '—' }}
                    </div>
                  </template>
                  <template v-else>
                    <select
                      v-model="form.title"
                      class="w-full bg-white border-2 border-gray-100 rounded-2xl px-5 py-4 text-sm font-bold focus:border-[var(--color-primary)] focus:ring-4 focus:ring-[var(--color-primary)]/10 transition-all outline-none appearance-none"
                    >
                      <option value="" disabled>เลือก...</option>
                      <option value="นาย">นาย</option>
                      <option value="นาง">นาง</option>
                      <option value="นางสาว">นางสาว</option>
                    </select>
                  </template>
                </div>
                <div class="md:col-span-9 space-y-2.5">
                  <label class="block text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-widest ml-1 opacity-70">ชื่อ-นามสกุล</label>
                  <template v-if="!editMode">
                    <div class="bg-[var(--color-sand)]/50 px-5 py-4 rounded-2xl text-[var(--color-text-dark)] font-bold text-sm border border-transparent">
                      {{ auth.user?.name || '—' }}
                    </div>
                  </template>
                  <template v-else>
                    <input
                      v-model="form.name"
                      type="text"
                      class="w-full bg-white border-2 border-gray-100 rounded-2xl px-5 py-4 text-sm font-bold focus:border-[var(--color-primary)] focus:ring-4 focus:ring-[var(--color-primary)]/10 transition-all outline-none"
                      placeholder="ชื่อ-นามสกุลจริง"
                    />
                  </template>
                </div>

                <!-- Nickname & Blood Group -->
                <div class="md:col-span-6 space-y-2.5">
                  <label class="block text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-widest ml-1 opacity-70">ชื่อเล่น</label>
                  <template v-if="!editMode">
                    <div class="bg-[var(--color-sand)]/50 px-5 py-4 rounded-2xl text-[var(--color-text-dark)] font-bold text-sm border border-transparent">
                      {{ auth.user?.nickname || '—' }}
                    </div>
                  </template>
                  <template v-else>
                    <input
                      v-model="form.nickname"
                      type="text"
                      class="w-full bg-white border-2 border-gray-100 rounded-2xl px-5 py-4 text-sm font-bold focus:border-[var(--color-primary)] focus:ring-4 focus:ring-[var(--color-primary)]/10 transition-all outline-none"
                      placeholder="ระบุชื่อเล่น"
                    />
                  </template>
                </div>
                <div class="md:col-span-6 space-y-2.5">
                  <label class="block text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-widest ml-1 opacity-70">กรุ๊ปเลือด</label>
                  <template v-if="!editMode">
                    <div class="bg-[var(--color-sand)]/50 px-5 py-4 rounded-2xl text-[var(--color-text-dark)] font-bold text-sm border border-transparent">
                      {{ auth.user?.blood_group || '—' }}
                    </div>
                  </template>
                  <template v-else>
                    <select
                      v-model="form.blood_group"
                      class="w-full bg-white border-2 border-gray-100 rounded-2xl px-5 py-4 text-sm font-bold focus:border-[var(--color-primary)] focus:ring-4 focus:ring-[var(--color-primary)]/10 transition-all outline-none"
                    >
                      <option value="">ไม่ระบุ</option>
                      <option value="A">A</option>
                      <option value="B">B</option>
                      <option value="O">O</option>
                      <option value="AB">AB</option>
                    </select>
                  </template>
                </div>

                <!-- ID Card -->
                <div class="md:col-span-12 space-y-2.5">
                  <label class="block text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-widest ml-1 opacity-70">เลขบัตรประชาชน</label>
                  <template v-if="!editMode">
                    <div class="bg-[var(--color-sand)]/50 px-5 py-4 rounded-2xl text-[var(--color-text-dark)] font-bold text-sm border border-transparent flex items-center justify-between">
                      <span>{{ maskedIdCard || '—' }}</span>
                      <span class="material-symbols-rounded text-gray-300 text-[18px]">lock</span>
                    </div>
                  </template>
                  <template v-else>
                    <input
                      v-model="form.id_card"
                      type="text"
                      maxlength="13"
                      @input="form.id_card = form.id_card.replace(/\D/g, '')"
                      class="w-full bg-white border-2 border-gray-100 rounded-2xl px-5 py-4 text-sm font-bold focus:border-[var(--color-primary)] focus:ring-4 focus:ring-[var(--color-primary)]/10 transition-all outline-none"
                      placeholder="X-XXXX-XXXXX-XX-X"
                    />
                  </template>
                </div>
              </div>
            </div>

            <!-- Section: ข้อมูลติดต่อ -->
            <div class="space-y-8">
              <div class="flex items-center gap-4">
                <div class="h-px flex-1 bg-gray-100"></div>
                <h3 class="text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-[0.2em] px-4 py-1.5 bg-[var(--color-sand)] rounded-full border border-gray-100">ข้อมูลติดต่อ</h3>
                <div class="h-px flex-1 bg-gray-100"></div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2.5">
                  <label class="block text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-widest ml-1 opacity-70">เบอร์โทรศัพท์</label>
                  <template v-if="!editMode">
                    <div class="bg-[var(--color-sand)]/50 px-5 py-4 rounded-2xl text-[var(--color-text-dark)] font-bold text-sm border border-transparent">
                      {{ auth.user?.phone || '—' }}
                    </div>
                  </template>
                  <template v-else>
                    <input
                      v-model="form.phone"
                      type="tel"
                      class="w-full bg-white border-2 border-gray-100 rounded-2xl px-5 py-4 text-sm font-bold focus:border-[var(--color-primary)] focus:ring-4 focus:ring-[var(--color-primary)]/10 transition-all outline-none"
                      placeholder="08X-XXX-XXXX"
                    />
                  </template>
                </div>
                <div class="space-y-2.5">
                  <label class="block text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-widest ml-1 opacity-70">อีเมล (ใช้สำหรับเข้าสู่ระบบ)</label>
                  <div class="bg-[var(--color-sand)]/50 px-5 py-4 rounded-2xl text-[var(--color-text-dark)] font-bold text-sm border border-transparent flex items-center justify-between opacity-60">
                    <span class="truncate">{{ auth.user?.email || '—' }}</span>
                    <span class="material-symbols-rounded text-[var(--color-primary)] text-[18px] shrink-0" style="font-variation-settings:'FILL' 1">verified_user</span>
                  </div>
                </div>

                <!-- Emergency Contact -->
                <div class="space-y-2.5">
                  <label class="block text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-widest ml-1 opacity-70">ผู้ติดต่อฉุกเฉิน</label>
                  <template v-if="!editMode">
                    <div class="bg-[var(--color-sand)]/50 px-5 py-4 rounded-2xl text-[var(--color-text-dark)] font-bold text-sm border border-transparent">
                      {{ auth.user?.emergency_contact || '—' }}
                    </div>
                  </template>
                  <template v-else>
                    <input
                      v-model="form.emergency_contact"
                      type="text"
                      class="w-full bg-white border-2 border-gray-100 rounded-2xl px-5 py-4 text-sm font-bold focus:border-[var(--color-primary)] focus:ring-4 focus:ring-[var(--color-primary)]/10 transition-all outline-none"
                      placeholder="ระบุชื่อผู้ติดต่อฉุกเฉิน"
                    />
                  </template>
                </div>
                <div class="space-y-2.5">
                  <label class="block text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-widest ml-1 opacity-70">เบอร์โทรฉุกเฉิน</label>
                  <template v-if="!editMode">
                    <div class="bg-[var(--color-sand)]/50 px-5 py-4 rounded-2xl text-[var(--color-text-dark)] font-bold text-sm border border-transparent">
                      {{ auth.user?.emergency_phone || '—' }}
                    </div>
                  </template>
                  <template v-else>
                    <input
                      v-model="form.emergency_phone"
                      type="tel"
                      class="w-full bg-white border-2 border-gray-100 rounded-2xl px-5 py-4 text-sm font-bold focus:border-[var(--color-primary)] focus:ring-4 focus:ring-[var(--color-primary)]/10 transition-all outline-none"
                      placeholder="08X-XXX-XXXX"
                    />
                  </template>
                </div>
              </div>
            </div>

            <!-- Section: ข้อมูลเพิ่มเติม -->
            <div class="space-y-8">
              <div class="flex items-center gap-4">
                <div class="h-px flex-1 bg-gray-100"></div>
                <h3 class="text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-[0.2em] px-4 py-1.5 bg-[var(--color-sand)] rounded-full border border-gray-100">ข้อมูลเพิ่มเติม</h3>
                <div class="h-px flex-1 bg-gray-100"></div>
              </div>

              <div class="space-y-6">
                <div class="space-y-2.5">
                  <label class="block text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-widest ml-1 opacity-70">การแพ้อาหาร / สิ่งที่ควรระวัง</label>
                  <template v-if="!editMode">
                    <div class="bg-[var(--color-sand)]/50 px-5 py-4 rounded-2xl text-[var(--color-text-dark)] font-bold text-sm border border-transparent">
                      {{ auth.user?.allergies || 'ไม่มี' }}
                    </div>
                  </template>
                  <template v-else>
                    <input
                      v-model="form.allergies"
                      type="text"
                      class="w-full bg-white border-2 border-gray-100 rounded-2xl px-5 py-4 text-sm font-bold focus:border-[var(--color-primary)] focus:ring-4 focus:ring-[var(--color-primary)]/10 transition-all outline-none"
                      placeholder="เช่น แพ้อาหารทะเล, ไม่ทานเนื้อ, แพ้ถั่ว"
                    />
                  </template>
                </div>

                <div class="space-y-2.5">
                  <label class="block text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-widest ml-1 opacity-70">หมายเหตุสุขภาพ / โรคประจำตัว</label>
                  <template v-if="!editMode">
                    <div class="bg-[var(--color-sand)]/50 px-5 py-5 rounded-2xl text-[var(--color-text-dark)] font-medium text-sm leading-relaxed border border-transparent">
                      {{ auth.user?.health_notes || '—' }}
                    </div>
                  </template>
                  <template v-else>
                    <textarea
                      v-model="form.health_notes"
                      rows="3"
                      class="w-full bg-white border-2 border-gray-100 rounded-2xl px-5 py-4 text-sm font-bold focus:border-[var(--color-primary)] focus:ring-4 focus:ring-[var(--color-primary)]/10 transition-all outline-none resize-none"
                      placeholder="ระบุโรคประจำตัว หรือประวัติการแพ้ยา (ถ้ามี)"
                    ></textarea>
                  </template>
                </div>
              </div>
            </div>
          </div>

          <!-- Rewards Section Refined -->
          <div class="mt-16 p-1 bg-gradient-to-br from-[var(--color-primary)] to-[var(--color-primary)]/80 rounded-[2.5rem] shadow-2xl shadow-[var(--color-primary)]/20 overflow-hidden relative group">
            <div class="bg-white/5 backdrop-blur-sm p-8 md:p-12 rounded-[2.4rem] relative z-10 flex flex-col md:flex-row items-center gap-10">
              <!-- Left: Points Badge -->
              <div class="shrink-0 relative">
                <div class="w-24 h-24 rounded-3xl bg-white flex flex-col items-center justify-center shadow-xl rotate-3 group-hover:rotate-0 transition-transform duration-500">
                  <span class="text-3xl font-black text-[var(--color-primary)] leading-tight">{{ loyaltyPoints?.toLocaleString() || 0 }}</span>
                  <span class="text-[9px] font-black text-[var(--color-text-muted)] uppercase tracking-widest">แต้ม</span>
                </div>
                <div class="absolute -bottom-3 -right-3 w-10 h-10 rounded-full bg-[var(--color-gold)] border-4 border-white flex items-center justify-center text-white shadow-lg">
                  <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 1">stars</span>
                </div>
              </div>

              <!-- Center: Progress -->
              <div class="flex-1 text-center md:text-left w-full">
                <h3 class="text-2xl font-black text-white mb-3 tracking-tight">สะสมแต้มพรีเมียม</h3>
                
                <template v-if="loyaltyPoints !== null && loyaltyNextTier">
                  <p class="text-white/80 text-sm font-bold mb-5">
                    อีก <span class="text-white text-lg px-1">{{ loyaltyNextTier.points_needed.toLocaleString() }}</span> แต้ม เพื่อเลื่อนระดับเป็น 
                    <strong class="text-[var(--color-accent-light)] font-black uppercase tracking-wider ml-1">{{ loyaltyNextTier.tier }}</strong>
                  </p>
                  
                  <!-- Progress Bar -->
                  <div class="relative w-full h-3 bg-white/20 rounded-full overflow-hidden mb-2">
                    <div 
                      class="absolute top-0 left-0 h-full bg-gradient-to-r from-[var(--color-accent-light)] to-white rounded-full transition-all duration-1000"
                      :style="{ width: Math.min(100, (loyaltyPoints / (loyaltyPoints + loyaltyNextTier.points_needed)) * 100) + '%' }"
                    ></div>
                  </div>
                  <div class="flex justify-between text-[10px] font-black text-white/60 uppercase tracking-widest">
                    <span>{{ loyaltyPoints.toLocaleString() }} แต้มปัจจุบัน</span>
                    <span>ระดับถัดไป</span>
                  </div>
                </template>
                <template v-else>
                  <p class="text-white/80 text-sm font-bold leading-relaxed max-w-md">
                    สะสมแต้มจากทุกการจองเดินทาง เพื่อรับสิทธิพิเศษมากมายและส่วนลดสำหรับทริปถัดไปของคุณ
                  </p>
                </template>
              </div>

              <!-- Right: CTA -->
              <router-link
                to="/loyalty"
                class="bg-white text-[var(--color-primary)] px-10 py-4 rounded-2xl font-black hover:bg-[var(--color-sand)] transition-all shrink-0 shadow-xl hover:-translate-y-1 active:scale-95 flex items-center gap-3"
              >
                <span>รายละเอียดแต้ม</span>
                <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
              </router-link>
            </div>
            
            <!-- Abstract Decorations -->
            <div class="absolute -right-20 -top-20 w-64 h-64 border-[40px] border-white/5 rounded-full pointer-events-none group-hover:scale-110 transition-transform duration-700"></div>
            <div class="absolute -left-10 -bottom-10 w-32 h-32 bg-white/5 rounded-full blur-2xl pointer-events-none"></div>
          </div>
        </div>

        <!-- ─── TAB: ความปลอดภัย ─── -->
        <div v-if="activeTab === 'security'" class="bg-white p-8 md:p-12 rounded-[2.5rem] shadow-[0_20px_50px_rgba(0,0,0,0.04)] border border-gray-100">
          <div class="mb-12 pb-8 border-b border-gray-100 flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div>
              <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center text-blue-600">
                  <span class="material-symbols-rounded text-[24px]">security</span>
                </div>
                <h2 class="text-3xl font-black text-[var(--color-text-dark)] tracking-tight">ความปลอดภัย</h2>
              </div>
              <p class="text-[var(--color-text-muted)] font-medium ml-13">เปลี่ยนรหัสผ่านและตรวจสอบสถานะความปลอดภัยของบัญชี</p>
            </div>
            
            <!-- Security Status Badge -->
            <div class="flex items-center gap-3 px-5 py-3 bg-green-50 rounded-2xl border border-green-100 self-start md:self-center">
              <div class="w-2.5 h-2.5 rounded-full bg-green-500 animate-pulse"></div>
              <span class="text-xs font-black text-green-700 uppercase tracking-widest">บัญชีปลอดภัยแล้ว</span>
              <span class="material-symbols-rounded text-green-600 text-[18px]">verified</span>
            </div>
          </div>

          <div class="max-w-xl space-y-8">
            <div class="p-6 bg-[var(--color-sand)]/50 rounded-2xl border border-gray-100 flex gap-4">
              <span class="material-symbols-rounded text-blue-500 mt-0.5">info</span>
              <p class="text-xs font-bold text-[var(--color-text-muted)] leading-relaxed">
                แนะนำให้ใช้รหัสผ่านที่ประกอบด้วย ตัวอักษรพิมพ์เล็ก พิมพ์ใหญ่ ตัวเลข และสัญลักษณ์พิเศษ เพื่อความปลอดภัยสูงสุดของข้อมูลการจองของคุณ
              </p>
            </div>

            <div class="space-y-6">
              <div class="space-y-2.5">
                <label class="block text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-widest ml-1 opacity-70">รหัสผ่านใหม่</label>
                <input
                  v-model="form.password"
                  type="password"
                  class="w-full bg-white border-2 border-gray-100 rounded-2xl px-5 py-4 text-sm font-bold focus:border-[var(--color-primary)] focus:ring-4 focus:ring-[var(--color-primary)]/10 transition-all outline-none"
                  placeholder="••••••••"
                />
              </div>
              <div class="space-y-2.5">
                <label class="block text-[11px] font-black text-[var(--color-text-muted)] uppercase tracking-widest ml-1 opacity-70">ยืนยันรหัสผ่านใหม่</label>
                <input
                  v-model="form.password_confirmation"
                  type="password"
                  class="w-full bg-white border-2 border-gray-100 rounded-2xl px-5 py-4 text-sm font-bold focus:border-[var(--color-primary)] focus:ring-4 focus:ring-[var(--color-primary)]/10 transition-all outline-none"
                  placeholder="••••••••"
                />
              </div>
            </div>

            <div class="pt-4">
              <button
                @click="handleSave"
                :disabled="saving"
                class="w-full md:w-auto bg-[var(--color-primary)] text-white px-12 py-4 rounded-2xl font-black shadow-lg shadow-[var(--color-primary)]/20 hover:bg-[var(--color-accent)] hover:-translate-y-1 active:scale-95 transition-all flex items-center justify-center gap-3 disabled:opacity-50"
              >
                <span v-if="saving" class="material-symbols-rounded animate-spin text-[20px]">refresh</span>
                <span v-else class="material-symbols-rounded text-[20px]">key</span>
                <span v-if="!saving">อัปเดตรหัสผ่าน</span>
              </button>
            </div>
          </div>
        </div>

        <!-- ─── TAB: ออกจากระบบ ─── -->
        <div v-if="activeTab === 'logout'" class="bg-white p-8 md:p-12 rounded-[2rem] shadow-[0_8px_40px_rgba(0,0,0,0.04)] border border-gray-100">
          <div class="mb-10 pb-8 border-b border-gray-100">
            <h2 class="text-3xl font-black text-[var(--color-text-dark)] mb-2">ออกจากระบบ</h2>
            <p class="text-[var(--color-text-muted)] font-medium">คุณกำลังล็อกอินอยู่ในบัญชี <strong class="text-[var(--color-primary)]">{{ auth.user?.email }}</strong></p>
          </div>
          <button
            @click="handleLogout"
            class="bg-white text-red-500 border-2 border-red-100 px-8 py-4 rounded-[1rem] font-black hover:bg-red-50 hover:border-red-500 transition-colors flex items-center justify-center gap-3 w-full md:w-auto shadow-sm"
          >
            <span class="material-symbols-rounded text-[24px]">logout</span>
            ลงชื่อออกจากระบบ
          </button>
        </div>

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
  if (!id) return '';
  if (id.length < 4) return id;
  return id.slice(0, 1) + '-XXXX-XXXXX-' + id.slice(-2, -1) + '-' + id.slice(-1);
});

const providerMap = {
  google:   { label: 'สมัครด้วย Google',   class: 'bg-red-50 text-red-600 border border-red-200',     icon: '<svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>' },
  facebook: { label: 'สมัครด้วย Facebook', class: 'bg-blue-50 text-blue-700 border border-blue-200',   icon: '<svg class="w-3.5 h-3.5" fill="#1877F2" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>' },
  line:     { label: 'สมัครด้วย LINE',     class: 'bg-green-50 text-green-700 border border-green-200', icon: '<svg class="w-3.5 h-3.5" fill="#06C755" viewBox="0 0 24 24"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.349 0 .63.285.63.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.281.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.070 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg>' },
};

const providerBadge = computed(() => {
  const p = auth.user?.social_provider;
  return providerMap[p] || { label: 'สมัครด้วยอีเมล', class: 'bg-gray-100 text-gray-700 border border-gray-200', icon: '<span class="material-symbols-rounded text-[14px]">mail</span>' };
});

function formatJoinDate(iso) {
  if (!iso) return '';
  return new Date(iso).toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' });
}
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
  animation: fadeIn 0.3s ease-out forwards;
}

.animate-in {
  animation: fadeIn 0.4s ease-out forwards;
}

.slide-in-from-left {
  animation: slideInLeft 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-10px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes slideInLeft {
  from { opacity: 0; transform: translate(-20px, -50%); }
  to { opacity: 1; transform: translate(0, -50%); }
}

.scrollbar-none {
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.scrollbar-none::-webkit-scrollbar {
  display: none;
}

/* Custom shadow for cards */
.shadow-premium {
  shadow: 0 20px 50px rgba(0, 0, 0, 0.04);
}

/* Input focus glow */
input:focus, select:focus, textarea:focus {
  box-shadow: 0 0 0 4px rgba(var(--color-primary-rgb), 0.1);
}

/* Transitions */
.transition-all {
  transition-duration: 200ms;
  transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
}

.ml-13 {
  margin-left: 3.25rem;
}
</style>
