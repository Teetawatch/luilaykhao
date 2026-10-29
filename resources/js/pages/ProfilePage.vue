<template>
  <div class="min-h-screen bg-[var(--color-sand)] pb-24 font-anuphan">

    <!-- ══════════════════════════════════════════
         PROFILE HEADER
    ══════════════════════════════════════════ -->
    <section class="relative">
      <div class="absolute inset-x-0 top-0 h-36 md:h-44 overflow-hidden bg-[var(--color-primary)]">
        <img src="/images/khaochangphueak.webp" alt="" class="w-full h-full object-cover opacity-30" />
      </div>

      <div class="relative max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-16 md:pt-24">
        <div class="bg-white rounded-3xl border border-[var(--color-sand-dark)] p-5 sm:p-6 md:p-8">
          <div class="flex flex-col md:flex-row md:items-center gap-6">

            <!-- Avatar -->
            <div class="flex flex-col items-center md:items-start gap-3 shrink-0 -mt-14 md:-mt-20">
              <div class="relative">
                <div class="w-28 h-28 md:w-32 md:h-32 rounded-full ring-4 ring-white overflow-hidden bg-[var(--color-sand)] flex items-center justify-center">
                  <img
                    v-if="avatarPreview || auth.user?.avatar_url"
                    :src="avatarPreview || auth.user.avatar_url"
                    alt="รูปโปรไฟล์"
                    class="w-full h-full object-cover"
                    :class="{ 'opacity-60': uploadingAvatar }"
                  />
                  <span v-else class="text-4xl font-extrabold text-[var(--color-primary)]">{{ initial }}</span>
                  <span v-if="uploadingAvatar" class="absolute inset-0 flex items-center justify-center">
                    <span class="material-symbols-rounded text-white text-[28px] animate-spin">progress_activity</span>
                  </span>
                </div>
                <label
                  for="avatar-upload"
                  class="absolute bottom-0.5 right-0.5 w-10 h-10 rounded-full ring-4 ring-white bg-[var(--color-accent)] hover:bg-[var(--color-primary)] text-white flex items-center justify-center cursor-pointer transition-colors"
                  :title="avatarFile ? 'เลือกรูปอื่น' : 'เปลี่ยนรูปโปรไฟล์'"
                >
                  <span class="material-symbols-rounded text-[20px]">photo_camera</span>
                  <span class="sr-only">เปลี่ยนรูปโปรไฟล์</span>
                </label>
                <input id="avatar-upload" type="file" class="hidden" accept="image/*" @change="handleAvatarChange" />
              </div>

              <!-- รูปที่เลือกแล้วยังไม่บันทึก — ปุ่มอยู่ใต้รูปเลย ไม่ต้องไปหาแถบด้านบน -->
              <div v-if="avatarFile" class="flex items-center gap-2">
                <button
                  type="button"
                  class="btn-primary !px-4 !py-2 !text-[13px]"
                  :disabled="uploadingAvatar"
                  @click="uploadAvatar"
                >
                  {{ uploadingAvatar ? 'กำลังบันทึก…' : 'บันทึกรูป' }}
                </button>
                <button type="button" class="btn-ghost !px-3 !py-2 !text-[13px]" :disabled="uploadingAvatar" @click="cancelAvatarUpload">
                  ยกเลิก
                </button>
              </div>
            </div>

            <!-- Identity -->
            <div class="flex-1 min-w-0 text-center md:text-left">
              <div class="flex flex-col md:flex-row md:items-center gap-2 md:gap-3">
                <h1 class="text-2xl md:text-3xl font-extrabold text-[var(--color-text-dark)] tracking-tight truncate">
                  {{ displayName }}
                </h1>
                <span
                  v-if="loyaltyTierLabel"
                  class="self-center md:self-auto inline-flex items-center gap-1 rounded-full bg-[var(--color-accent)]/10 px-3 py-1 text-[12px] font-bold text-[var(--color-accent)]"
                >
                  <span class="material-symbols-rounded text-[16px]" style="font-variation-settings:'FILL' 1">workspace_premium</span>
                  {{ loyaltyTierLabel }}
                </span>
              </div>
              <p v-if="auth.user?.nickname" class="mt-1 text-sm font-semibold text-[var(--color-text-muted)]">ชื่อเล่น {{ auth.user.nickname }}</p>

              <ul class="mt-4 flex flex-wrap justify-center md:justify-start gap-x-5 gap-y-2 text-[13px] font-medium text-[var(--color-text-mid)]">
                <li v-if="realEmail" class="flex items-center gap-1.5 min-w-0">
                  <span class="material-symbols-rounded text-[18px] text-[var(--color-text-muted)]">mail</span>
                  <span class="truncate">{{ realEmail }}</span>
                </li>
                <li v-if="auth.user?.phone" class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-[18px] text-[var(--color-text-muted)]">call</span>
                  {{ auth.user.phone }}
                </li>
                <li v-if="auth.user?.created_at" class="flex items-center gap-1.5">
                  <span class="material-symbols-rounded text-[18px] text-[var(--color-text-muted)]">calendar_today</span>
                  สมาชิกตั้งแต่ {{ formatJoinDate(auth.user.created_at) }}
                </li>
              </ul>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-3 md:w-[22rem] shrink-0 rounded-2xl border border-[var(--color-sand-dark)] divide-x divide-[var(--color-sand-dark)]">
              <router-link to="/loyalty" class="stat-cell">
                <span class="stat-label">แต้มสะสม</span>
                <span class="stat-value">{{ loyaltyPoints === null ? '–' : loyaltyPoints.toLocaleString() }}</span>
              </router-link>
              <router-link to="/my-bookings" class="stat-cell">
                <span class="stat-label">ไปแล้ว</span>
                <span class="stat-value">{{ loyaltyPoints === null ? '–' : loyaltyTrips.toLocaleString() }}<span class="stat-unit">ทริป</span></span>
              </router-link>
              <button type="button" class="stat-cell" @click="openTab('info')">
                <span class="stat-label">โปรไฟล์</span>
                <span class="stat-value" :class="profileCompletion === 100 ? 'text-[var(--color-accent)]' : ''">{{ profileCompletion }}<span class="stat-unit">%</span></span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ══════════════════════════════════════════
         BODY
    ══════════════════════════════════════════ -->
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">

      <!-- Email verification -->
      <div v-if="showVerifyBanner" class="notice notice--warn mb-4">
        <span class="material-symbols-rounded text-[22px] text-amber-600 shrink-0">mark_email_unread</span>
        <div class="flex-1 min-w-0">
          <p class="font-bold text-sm text-[var(--color-text-dark)]">ยังไม่ได้ยืนยันอีเมล</p>
          <p class="text-[13px] text-[var(--color-text-mid)] leading-relaxed mt-0.5">
            ยืนยัน <strong class="font-bold">{{ auth.user?.email }}</strong> ไว้สักครั้ง
            เผื่อวันไหนลืมรหัสผ่าน เราจะช่วยกู้บัญชีคืนให้ได้ทันทีครับ
          </p>
        </div>
        <button type="button" class="btn-primary shrink-0" :disabled="resendingVerification" @click="resendVerification">
          {{ resendingVerification ? 'กำลังส่ง…' : 'ส่งลิงก์ยืนยัน' }}
        </button>
      </div>

      <div v-if="verifyNotice" class="notice mb-4" :class="verifyNotice.tone === 'good' ? 'notice--good' : 'notice--warn'">
        <span
          class="material-symbols-rounded text-[22px] shrink-0"
          :class="verifyNotice.tone === 'good' ? 'text-[var(--color-accent)]' : 'text-amber-600'"
        >{{ verifyNotice.tone === 'good' ? 'verified' : 'link_off' }}</span>
        <p class="text-sm font-bold text-[var(--color-text-dark)] flex-1">{{ verifyNotice.message }}</p>
        <button type="button" class="text-[var(--color-text-muted)] hover:text-[var(--color-text-dark)] shrink-0" aria-label="ปิด" @click="verifyNotice = null">
          <span class="material-symbols-rounded text-[20px]">close</span>
        </button>
      </div>

      <!-- Mobile tabs -->
      <div class="lg:hidden -mx-4 px-4 mb-4 overflow-x-auto scrollbar-none">
        <div class="inline-flex gap-1 p-1 rounded-2xl bg-white border border-[var(--color-sand-dark)]" role="tablist">
          <button
            v-for="tab in tabs"
            :key="tab.key"
            type="button"
            role="tab"
            :aria-selected="activeTab === tab.key"
            class="flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-bold whitespace-nowrap transition-colors"
            :class="activeTab === tab.key ? 'bg-[var(--color-primary)] text-white' : 'text-[var(--color-text-mid)]'"
            @click="openTab(tab.key)"
          >
            <span class="material-symbols-rounded text-[18px]">{{ tab.icon }}</span>
            {{ tab.label }}
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8">

        <!-- Sidebar (desktop) -->
        <aside class="hidden lg:block lg:col-span-3">
          <div class="sticky top-24 space-y-4">
            <nav class="bg-white rounded-3xl border border-[var(--color-sand-dark)] p-2" role="tablist" aria-label="หมวดการตั้งค่า">
              <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                role="tab"
                :aria-selected="activeTab === tab.key"
                class="side-tab"
                :class="{ 'side-tab--active': activeTab === tab.key }"
                @click="openTab(tab.key)"
              >
                <span class="material-symbols-rounded text-[22px]" :style="activeTab === tab.key ? FILLED : ''">{{ tab.icon }}</span>
                <span class="flex-1 text-left">
                  <span class="block">{{ tab.label }}</span>
                  <span class="block text-[12px] font-medium" :class="activeTab === tab.key ? 'text-white/70' : 'text-[var(--color-text-muted)]'">{{ tab.hint }}</span>
                </span>
              </button>
            </nav>

            <div class="bg-white rounded-3xl border border-[var(--color-sand-dark)] p-2">
              <router-link v-for="link in quickLinks" :key="link.to" :to="link.to" class="side-link">
                <span class="material-symbols-rounded text-[20px] text-[var(--color-text-muted)]">{{ link.icon }}</span>
                <span class="flex-1">{{ link.label }}</span>
                <span class="material-symbols-rounded text-[18px] text-[var(--color-text-muted)]">chevron_right</span>
              </router-link>
              <div class="h-px bg-[var(--color-sand-dark)] my-1.5 mx-3"></div>
              <button type="button" class="side-link !text-red-600 hover:!bg-red-50 w-full" @click="handleLogout">
                <span class="material-symbols-rounded text-[20px]">logout</span>
                <span class="flex-1 text-left">ออกจากระบบ</span>
              </button>
            </div>
          </div>
        </aside>

        <!-- Main -->
        <div class="lg:col-span-9 min-w-0 space-y-6">

          <!-- ─── TAB: ข้อมูลส่วนตัว ─── -->
          <template v-if="activeTab === 'info'">

            <!-- Completion -->
            <div v-if="missingFields.length" class="bg-white rounded-3xl border border-[var(--color-sand-dark)] p-5 md:p-6">
              <div class="flex items-center justify-between gap-4">
                <div>
                  <p class="font-bold text-[var(--color-text-dark)]">กรอกโปรไฟล์ให้ครบ {{ profileCompletion }}%</p>
                  <p class="text-[13px] text-[var(--color-text-muted)] mt-0.5">ข้อมูลเหล่านี้ถูกเติมให้อัตโนมัติตอนจอง และช่วยให้ทีมงานดูแลคุณระหว่างทริปได้</p>
                </div>
                <button v-if="!editMode" type="button" class="btn-primary shrink-0 hidden sm:inline-flex" @click="startEdit">กรอกเลย</button>
              </div>
              <div class="mt-4 h-2 rounded-full bg-[var(--color-sand)] overflow-hidden">
                <div class="h-full rounded-full bg-[var(--color-accent)] transition-[width] duration-700" :style="{ width: profileCompletion + '%' }"></div>
              </div>
              <div class="mt-4 flex flex-wrap gap-2">
                <button
                  v-for="field in missingFields"
                  :key="field.key"
                  type="button"
                  class="inline-flex items-center gap-1 rounded-full border border-dashed border-[var(--color-sand-dark)] px-3 py-1.5 text-[12px] font-bold text-[var(--color-text-mid)] hover:border-[var(--color-accent)] hover:text-[var(--color-accent)] transition-colors"
                  @click="startEdit(field.key)"
                >
                  <span class="material-symbols-rounded text-[16px]">add</span>
                  {{ field.label }}
                </button>
              </div>
            </div>

            <!-- Field sections -->
            <section
              v-for="section in infoSections"
              :key="section.key"
              class="bg-white rounded-3xl border border-[var(--color-sand-dark)]"
            >
              <header class="flex items-center justify-between gap-4 px-5 md:px-7 pt-5 md:pt-6">
                <div class="flex items-center gap-3 min-w-0">
                  <span class="w-10 h-10 rounded-xl bg-[var(--color-sand)] flex items-center justify-center text-[var(--color-primary)] shrink-0">
                    <span class="material-symbols-rounded text-[22px]">{{ section.icon }}</span>
                  </span>
                  <div class="min-w-0">
                    <h2 class="text-lg font-extrabold text-[var(--color-text-dark)]">{{ section.title }}</h2>
                    <p class="text-[13px] text-[var(--color-text-muted)] truncate">{{ section.desc }}</p>
                  </div>
                </div>
                <button
                  v-if="!editMode && section.key === 'basic'"
                  type="button"
                  class="btn-ghost shrink-0"
                  @click="startEdit()"
                >
                  <span class="material-symbols-rounded text-[18px]">edit</span>
                  แก้ไข
                </button>
              </header>

              <!-- View mode -->
              <dl v-if="!editMode" class="mt-3 px-5 md:px-7 pb-3 divide-y divide-[var(--color-sand-dark)]">
                <div v-for="field in section.fields" :key="field.key" class="py-3.5 grid grid-cols-1 sm:grid-cols-3 gap-1 sm:gap-4">
                  <dt class="text-[13px] font-semibold text-[var(--color-text-muted)]">{{ field.label }}</dt>
                  <dd class="sm:col-span-2 text-sm min-w-0 break-words" :class="field.multiline ? 'whitespace-pre-line leading-relaxed' : ''">
                    <span v-if="displayValue(field)" class="font-semibold text-[var(--color-text-dark)]">{{ displayValue(field) }}</span>
                    <span v-else class="text-[var(--color-text-muted)]/70">{{ field.emptyText || 'ยังไม่ได้ระบุ' }}</span>
                    <span v-if="field.locked && displayValue(field)" class="ml-2 inline-flex items-center gap-1 align-middle text-[11px] font-bold text-[var(--color-text-muted)]">
                      <span class="material-symbols-rounded text-[14px]">lock</span>เข้ารหัส
                    </span>
                  </dd>
                </div>
              </dl>

              <!-- Edit mode -->
              <div v-else class="px-5 md:px-7 pt-5 pb-6">
                <p v-if="section.note" class="mb-5 flex gap-2 rounded-2xl bg-[var(--color-sand)] p-3.5 text-[13px] text-[var(--color-text-mid)] leading-relaxed">
                  <span class="material-symbols-rounded text-[18px] text-[var(--color-accent)] shrink-0">shield</span>
                  {{ section.note }}
                </p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-5 gap-y-5">
                  <div v-for="field in section.fields" :key="field.key" :class="field.wide ? 'md:col-span-2' : ''">
                    <label :for="`f-${field.key}`" class="field-label">
                      {{ field.label }}
                      <span v-if="field.required" class="text-red-500">*</span>
                      <span v-if="field.recommend" class="ml-1 font-semibold text-amber-600">แนะนำให้กรอก</span>
                    </label>

                    <div v-if="field.key === 'email'" class="field-input field-input--readonly">
                      <span class="truncate">{{ realEmail || '—' }}</span>
                      <span class="material-symbols-rounded text-[16px] ml-auto shrink-0">lock</span>
                    </div>
                    <select v-else-if="field.options" :id="`f-${field.key}`" v-model="form[field.key]" class="field-input">
                      <option value="">ไม่ระบุ</option>
                      <option v-for="opt in field.options" :key="opt" :value="opt">{{ opt }}</option>
                    </select>
                    <textarea
                      v-else-if="field.multiline"
                      :id="`f-${field.key}`"
                      v-model="form[field.key]"
                      rows="3"
                      class="field-input resize-y"
                      :placeholder="field.placeholder"
                    ></textarea>
                    <input
                      v-else
                      :id="`f-${field.key}`"
                      v-model="form[field.key]"
                      :type="field.type || 'text'"
                      :inputmode="field.key === 'id_card' ? 'numeric' : undefined"
                      :maxlength="field.maxlength"
                      :autocomplete="field.autocomplete || 'off'"
                      class="field-input"
                      :class="{ 'field-input--error': fieldErrors[field.key] }"
                      :placeholder="field.placeholder"
                      @input="field.key === 'id_card' && (form.id_card = form.id_card.replace(/\D/g, ''))"
                    />

                    <p v-if="fieldErrors[field.key]" class="field-help !text-red-600">{{ fieldErrors[field.key] }}</p>
                    <p v-else-if="field.help" class="field-help">{{ field.help }}</p>
                  </div>
                </div>
              </div>
            </section>

            <!-- Sticky save bar -->
            <div v-if="editMode" class="sticky bottom-4 z-20">
              <div class="flex items-center justify-between gap-3 rounded-2xl bg-[var(--color-primary)] px-4 py-3 sm:px-5">
                <p class="text-sm font-bold text-white/85 hidden sm:block">{{ formDirty ? 'มีการแก้ไขที่ยังไม่บันทึก' : 'ยังไม่มีการเปลี่ยนแปลง' }}</p>
                <div class="flex items-center gap-2 ml-auto">
                  <button type="button" class="px-4 py-2.5 rounded-xl text-sm font-bold text-white hover:bg-white/10 transition-colors" :disabled="saving" @click="cancelEdit">
                    ยกเลิก
                  </button>
                  <button
                    type="button"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold bg-white text-[var(--color-primary)] hover:bg-[var(--color-sand)] transition-colors disabled:opacity-60"
                    :disabled="saving || !formDirty"
                    @click="saveProfile"
                  >
                    <span v-if="saving" class="material-symbols-rounded animate-spin text-[18px]">progress_activity</span>
                    {{ saving ? 'กำลังบันทึก…' : 'บันทึกข้อมูล' }}
                  </button>
                </div>
              </div>
            </div>

            <!-- Loyalty -->
            <router-link
              v-if="!editMode"
              to="/loyalty"
              class="group block rounded-3xl bg-[var(--color-primary)] p-6 md:p-7 text-white"
            >
              <div class="flex flex-col sm:flex-row sm:items-center gap-5">
                <div class="flex items-center gap-4 shrink-0">
                  <span class="w-14 h-14 rounded-2xl bg-white/10 flex items-center justify-center">
                    <span class="material-symbols-rounded text-[30px] text-[var(--color-gold)]" style="font-variation-settings:'FILL' 1">stars</span>
                  </span>
                  <div>
                    <p class="text-[13px] font-bold text-white/60">แต้มสะสม</p>
                    <p class="text-3xl font-extrabold tabular-nums leading-tight">{{ (loyaltyPoints ?? 0).toLocaleString() }}</p>
                  </div>
                </div>

                <div class="flex-1 min-w-0">
                  <template v-if="loyaltyNextTier">
                    <p class="text-sm font-semibold text-white/85">
                      อีก <strong class="text-white">{{ loyaltyNextTier.trips_needed.toLocaleString() }} ทริป</strong>
                      เลื่อนเป็น <strong class="text-[var(--color-accent-light)]">{{ loyaltyNextTier.label }}</strong>
                    </p>
                    <div class="mt-2.5 h-2 rounded-full bg-white/15 overflow-hidden">
                      <div class="h-full rounded-full bg-[var(--color-accent-light)] transition-[width] duration-700" :style="{ width: tierProgress + '%' }"></div>
                    </div>
                    <p class="mt-1.5 text-[12px] font-semibold text-white/55">ไปด้วยกันมาแล้ว {{ loyaltyTrips.toLocaleString() }} จาก {{ loyaltyNextTier.at.toLocaleString() }} ทริป</p>
                  </template>
                  <p v-else class="text-sm font-semibold text-white/80 leading-relaxed">
                    ได้แต้มจากทุกทริปที่เดินทางจริง ใช้เป็นส่วนลดทริปถัดไปได้
                  </p>
                </div>

                <span class="inline-flex items-center gap-1 text-sm font-bold text-white/85 group-hover:text-white shrink-0">
                  ดูรายละเอียด
                  <span class="material-symbols-rounded text-[18px] transition-transform group-hover:translate-x-0.5">arrow_forward</span>
                </span>
              </div>
            </router-link>
          </template>

          <!-- ─── TAB: โปรไฟล์สาธารณะ ─── -->
          <section v-if="activeTab === 'public'" class="bg-white rounded-3xl border border-[var(--color-sand-dark)] p-5 md:p-7">
            <div class="flex items-start gap-3 pb-5 border-b border-[var(--color-sand-dark)]">
              <span class="w-10 h-10 rounded-xl bg-[var(--color-sand)] flex items-center justify-center text-[var(--color-primary)] shrink-0">
                <span class="material-symbols-rounded text-[22px]">public</span>
              </span>
              <div>
                <h2 class="text-lg font-extrabold text-[var(--color-text-dark)]">โปรไฟล์สาธารณะ</h2>
                <p class="text-[13px] text-[var(--color-text-muted)] leading-relaxed mt-0.5">
                  หน้าเว็บที่แชร์ให้เพื่อนดูได้ — ทริปที่เดินจบ ระยะทางสะสม ตราที่ปลดล็อก และรูปจากรีวิวของคุณ
                </p>
              </div>
            </div>

            <div class="max-w-2xl pt-6 space-y-6">
              <div class="flex items-start justify-between gap-4 rounded-2xl border p-4 md:p-5 transition-colors"
                   :class="publicProfile.enabled ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/5' : 'border-[var(--color-sand-dark)]'">
                <div>
                  <p id="public-toggle-label" class="font-bold text-[var(--color-text-dark)]">เปิดให้คนอื่นดูโปรไฟล์ได้</p>
                  <p class="text-[13px] text-[var(--color-text-muted)] mt-1 leading-relaxed">
                    ปิดอยู่ = ไม่มีใครเปิดลิงก์ได้เลย อีเมล เบอร์โทร และการจองจะไม่ถูกแสดงไม่ว่ากรณีใด
                  </p>
                </div>
                <button
                  type="button"
                  role="switch"
                  :aria-checked="publicProfile.enabled"
                  aria-labelledby="public-toggle-label"
                  class="switch shrink-0"
                  :class="{ 'switch--on': publicProfile.enabled }"
                  @click="publicProfile.enabled = !publicProfile.enabled"
                >
                  <span class="switch-thumb"></span>
                </button>
              </div>

              <div>
                <label for="public-bio" class="field-label">แนะนำตัวสั้น ๆ</label>
                <input
                  id="public-bio"
                  v-model="publicProfile.bio"
                  type="text"
                  maxlength="160"
                  placeholder="เช่น เดินป่ามา 3 ปี ยังไม่เข็ด"
                  class="field-input"
                />
                <p class="field-help text-right tabular-nums">{{ (publicProfile.bio || '').length }}/160</p>
              </div>

              <div v-if="publicProfile.url && publicProfile.enabled" class="rounded-2xl bg-[var(--color-sand)] p-4">
                <p class="text-[12px] font-bold text-[var(--color-text-muted)] mb-2">ลิงก์โปรไฟล์ของคุณ</p>
                <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                  <a :href="publicProfile.url" target="_blank" rel="noopener" class="flex-1 min-w-0 truncate font-bold text-[var(--color-accent)] hover:underline">
                    {{ publicProfile.url }}
                  </a>
                  <div class="flex gap-2 shrink-0">
                    <button type="button" class="btn-ghost !bg-white" @click="copyPublicUrl">
                      <span class="material-symbols-rounded text-[18px]">{{ copiedPublicUrl ? 'check' : 'content_copy' }}</span>
                      {{ copiedPublicUrl ? 'คัดลอกแล้ว' : 'คัดลอก' }}
                    </button>
                    <a :href="publicProfile.url" target="_blank" rel="noopener" class="btn-ghost !bg-white">
                      <span class="material-symbols-rounded text-[18px]">open_in_new</span>
                      เปิดดู
                    </a>
                  </div>
                </div>
              </div>

              <button type="button" class="btn-primary" :disabled="savingPublicProfile || !publicDirty" @click="savePublicProfile">
                {{ savingPublicProfile ? 'กำลังบันทึก…' : 'บันทึกการตั้งค่า' }}
              </button>
            </div>
          </section>

          <!-- ─── TAB: ความปลอดภัย ─── -->
          <template v-if="activeTab === 'security'">
            <section class="bg-white rounded-3xl border border-[var(--color-sand-dark)] p-5 md:p-7">
              <h2 class="text-lg font-extrabold text-[var(--color-text-dark)]">การเข้าสู่ระบบ</h2>
              <dl class="mt-3 divide-y divide-[var(--color-sand-dark)]">
                <div class="py-3.5 flex items-center justify-between gap-4">
                  <dt class="text-[13px] font-semibold text-[var(--color-text-muted)]">วิธีที่ใช้สมัคร</dt>
                  <dd class="inline-flex items-center gap-2 text-sm font-semibold text-[var(--color-text-dark)]">
                    <span class="w-4 h-4 inline-flex items-center justify-center" v-html="providerBadge.icon"></span>
                    {{ providerBadge.label }}
                  </dd>
                </div>
                <div v-if="realEmail" class="py-3.5 flex items-center justify-between gap-4">
                  <dt class="text-[13px] font-semibold text-[var(--color-text-muted)] shrink-0">อีเมล</dt>
                  <dd class="flex items-center gap-2 text-sm font-semibold text-[var(--color-text-dark)] min-w-0">
                    <span class="truncate">{{ realEmail }}</span>
                    <span
                      class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold"
                      :class="auth.user?.email_verified === false ? 'bg-amber-50 text-amber-700' : 'bg-[var(--color-accent)]/10 text-[var(--color-accent)]'"
                    >{{ auth.user?.email_verified === false ? 'ยังไม่ยืนยัน' : 'ยืนยันแล้ว' }}</span>
                  </dd>
                </div>
              </dl>
            </section>

            <section class="bg-white rounded-3xl border border-[var(--color-sand-dark)] p-5 md:p-7">
              <h2 class="text-lg font-extrabold text-[var(--color-text-dark)]">{{ hasPassword ? 'เปลี่ยนรหัสผ่าน' : 'ตั้งรหัสผ่าน' }}</h2>
              <p class="text-[13px] text-[var(--color-text-muted)] mt-1 leading-relaxed">
                {{ hasPassword
                  ? 'ใช้อย่างน้อย 8 ตัว ผสมตัวพิมพ์ใหญ่ ตัวเลข หรือสัญลักษณ์ และไม่ใช้ข้อมูลที่เดาง่ายอย่างวันเกิด'
                  : `ตอนนี้คุณเข้าระบบด้วย ${providerName} อย่างเดียว ตั้งรหัสผ่านไว้เพื่อเข้าด้วยอีเมลได้อีกทาง` }}
              </p>

              <form class="max-w-md mt-6 space-y-5" @submit.prevent="savePassword">
                <!-- ช่องชื่อผู้ใช้ซ่อนไว้ให้ตัวจัดการรหัสผ่านของเบราว์เซอร์รู้ว่ารหัสนี้ของบัญชีไหน -->
                <input type="email" :value="auth.user?.email" autocomplete="username" class="hidden" readonly tabindex="-1" aria-hidden="true" />
                <div>
                  <label for="new-password" class="field-label">รหัสผ่านใหม่</label>
                  <div class="relative">
                    <input
                      id="new-password"
                      v-model="passwordForm.password"
                      :type="showPassword ? 'text' : 'password'"
                      autocomplete="new-password"
                      class="field-input !pr-12"
                      placeholder="อย่างน้อย 6 ตัวอักษร"
                    />
                    <button
                      type="button"
                      class="absolute right-3 top-1/2 -translate-y-1/2 text-[var(--color-text-muted)] hover:text-[var(--color-text-dark)]"
                      :aria-label="showPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'"
                      @click="showPassword = !showPassword"
                    >
                      <span class="material-symbols-rounded text-[22px]">{{ showPassword ? 'visibility_off' : 'visibility' }}</span>
                    </button>
                  </div>
                  <div v-if="passwordForm.password" class="mt-2.5">
                    <div class="flex gap-1">
                      <div
                        v-for="i in 5"
                        :key="i"
                        class="flex-1 h-1.5 rounded-full transition-colors duration-300"
                        :class="i <= passwordStrength.level ? passwordStrength.barColor : 'bg-[var(--color-sand-dark)]'"
                      ></div>
                    </div>
                    <p class="field-help font-bold" :class="passwordStrength.textColor">
                      {{ passwordTooShort ? `สั้นไป ต้องมีอย่างน้อย ${PASSWORD_MIN} ตัว` : `ความแข็งแรง: ${passwordStrength.label}` }}
                    </p>
                  </div>
                </div>

                <div>
                  <label for="confirm-password" class="field-label">ยืนยันรหัสผ่านใหม่</label>
                  <div class="relative">
                    <input
                      id="confirm-password"
                      v-model="passwordForm.password_confirmation"
                      :type="showPassword ? 'text' : 'password'"
                      autocomplete="new-password"
                      class="field-input !pr-12"
                      :class="{ 'field-input--error': passwordMismatch }"
                      placeholder="พิมพ์ซ้ำอีกครั้ง"
                    />
                    <span
                      v-if="passwordForm.password_confirmation"
                      class="absolute right-3 top-1/2 -translate-y-1/2 material-symbols-rounded text-[20px]"
                      :class="passwordMismatch ? 'text-red-500' : 'text-[var(--color-accent)]'"
                      style="font-variation-settings:'FILL' 1"
                    >{{ passwordMismatch ? 'cancel' : 'check_circle' }}</span>
                  </div>
                  <p v-if="passwordMismatch" class="field-help !text-red-600">รหัสผ่านไม่ตรงกัน</p>
                </div>

                <button type="submit" class="btn-primary" :disabled="!canSavePassword || savingPassword">
                  <span v-if="savingPassword" class="material-symbols-rounded animate-spin text-[18px]">progress_activity</span>
                  {{ savingPassword ? 'กำลังบันทึก…' : hasPassword ? 'เปลี่ยนรหัสผ่าน' : 'ตั้งรหัสผ่าน' }}
                </button>
              </form>
            </section>
          </template>

          <!-- Logout (mobile — desktop has it in the sidebar) -->
          <button
            type="button"
            class="lg:hidden w-full flex items-center justify-center gap-2 rounded-2xl border border-red-100 bg-white py-3.5 text-sm font-bold text-red-600 hover:bg-red-50 transition-colors"
            @click="handleLogout"
          >
            <span class="material-symbols-rounded text-[20px]">logout</span>
            ออกจากระบบ
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useToast } from '../lib/toast';
import api from '../lib/axios';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const toast = useToast();

const FILLED = "font-variation-settings:'FILL' 1";
const PASSWORD_MIN = 6; // ตรงกับ validation ฝั่ง AuthController::updateProfile
const AVATAR_MAX_BYTES = 5 * 1024 * 1024; // ตรงกับ max:5120 ฝั่งหลังบ้าน

const tabs = [
  { key: 'info',     label: 'ข้อมูลส่วนตัว',  icon: 'person',   hint: 'ชื่อ ติดต่อ สุขภาพ' },
  { key: 'public',   label: 'โปรไฟล์สาธารณะ', icon: 'public',   hint: 'หน้าที่แชร์ให้เพื่อนดู' },
  { key: 'security', label: 'ความปลอดภัย',    icon: 'lock',     hint: 'รหัสผ่านและการเข้าระบบ' },
];

const quickLinks = [
  { to: '/my-bookings', icon: 'confirmation_number', label: 'การจองของฉัน' },
  { to: '/loyalty',     icon: 'stars',               label: 'แต้มสะสม' },
  { to: '/passport',    icon: 'hiking',              label: 'สมุดสะสมการเดินทาง' },
];

// แท็บอยู่ใน ?tab= เพื่อให้ลิงก์ตรงเข้าแท็บได้ และกดย้อนกลับไม่หลุดออกจากหน้า
const activeTab = computed(() => (tabs.some((t) => t.key === route.query.tab) ? route.query.tab : 'info'));

function openTab(key) {
  if (key === activeTab.value) return;
  router.replace({ query: { ...route.query, tab: key === 'info' ? undefined : key } });
}

// ─── Info fields ───
const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];

const infoSections = [
  {
    key: 'basic',
    title: 'ข้อมูลพื้นฐาน',
    desc: 'ใช้กรอกชื่อผู้เดินทางและทำประกันให้อัตโนมัติ',
    icon: 'badge',
    note: 'เลขบัตรประชาชนถูกเข้ารหัสก่อนบันทึก และใช้สำหรับการจองทริปเท่านั้น',
    fields: [
      { key: 'title', label: 'คำนำหน้า', options: ['นาย', 'นาง', 'นางสาว'] },
      { key: 'name', label: 'ชื่อ-นามสกุล', required: true, placeholder: 'ชื่อ-นามสกุลจริง', autocomplete: 'name' },
      { key: 'nickname', label: 'ชื่อเล่น', placeholder: 'ระบุชื่อเล่น', autocomplete: 'nickname' },
      { key: 'blood_group', label: 'กรุ๊ปเลือด', options: BLOOD_GROUPS },
      { key: 'id_card', label: 'เลขบัตรประชาชน', placeholder: 'ตัวเลข 13 หลัก ไม่ต้องใส่ขีด', maxlength: 13, locked: true, wide: true },
    ],
  },
  {
    key: 'contact',
    title: 'ข้อมูลติดต่อ',
    desc: 'ทีมงานใช้ติดต่อคุณและคนใกล้ชิดเมื่อมีเหตุจำเป็น',
    icon: 'contact_phone',
    fields: [
      { key: 'phone', label: 'เบอร์โทรศัพท์', type: 'tel', placeholder: '08X-XXX-XXXX', autocomplete: 'tel' },
      { key: 'email', label: 'อีเมล', readonly: true },
      { key: 'emergency_contact', label: 'ผู้ติดต่อฉุกเฉิน', recommend: true, placeholder: 'เช่น คุณแม่, พี่สาว' },
      { key: 'emergency_phone', label: 'เบอร์ผู้ติดต่อฉุกเฉิน', type: 'tel', placeholder: '08X-XXX-XXXX' },
    ],
  },
  {
    key: 'health',
    title: 'ข้อมูลสุขภาพ',
    desc: 'ให้ทีมงานเตรียมอาหารและดูแลได้ถูกต้องระหว่างทริป',
    icon: 'health_and_safety',
    note: 'ข้อมูลสุขภาพถูกเข้ารหัสก่อนบันทึก และใช้เพื่อความปลอดภัยของคุณระหว่างทริปเท่านั้น',
    fields: [
      { key: 'allergies', label: 'การแพ้อาหาร / สิ่งที่ควรระวัง', placeholder: 'เช่น แพ้อาหารทะเล, ไม่ทานเนื้อหมู, แพ้ถั่ว', wide: true, emptyText: 'ไม่มี / ยังไม่ได้ระบุ' },
      { key: 'health_notes', label: 'โรคประจำตัว / หมายเหตุสุขภาพ', placeholder: 'โรคประจำตัว หรือประวัติการแพ้ยาที่ทีมงานควรทราบ (ถ้ามี)', multiline: true, wide: true, emptyText: 'ไม่มี / ยังไม่ได้ระบุ' },
    ],
  },
];

const EDITABLE_KEYS = ['title', 'name', 'phone', 'nickname', 'id_card', 'blood_group', 'emergency_contact', 'emergency_phone', 'allergies', 'health_notes'];

// ช่องที่นับเป็นความครบของโปรไฟล์
const COMPLETION_FIELDS = [
  { key: 'name', label: 'ชื่อ-นามสกุล' },
  { key: 'phone', label: 'เบอร์โทรศัพท์' },
  { key: 'nickname', label: 'ชื่อเล่น' },
  { key: 'id_card', label: 'เลขบัตรประชาชน' },
  { key: 'blood_group', label: 'กรุ๊ปเลือด' },
  { key: 'emergency_contact', label: 'ผู้ติดต่อฉุกเฉิน' },
  { key: 'emergency_phone', label: 'เบอร์ผู้ติดต่อฉุกเฉิน' },
  { key: 'allergies', label: 'การแพ้อาหาร' },
];

const editMode = ref(false);
const saving = ref(false);
const fieldErrors = ref({});
const form = ref(snapshotUser());

function snapshotUser() {
  const user = auth.user || {};
  return Object.fromEntries(EDITABLE_KEYS.map((key) => [key, user[key] ?? '']));
}

const formDirty = computed(() => {
  const original = snapshotUser();
  return EDITABLE_KEYS.some((key) => String(form.value[key] ?? '').trim() !== String(original[key] ?? '').trim());
});

const missingFields = computed(() => COMPLETION_FIELDS.filter((f) => !String(auth.user?.[f.key] ?? '').trim()));

const profileCompletion = computed(() => {
  if (!auth.user) return 0;
  return Math.round(((COMPLETION_FIELDS.length - missingFields.value.length) / COMPLETION_FIELDS.length) * 100);
});

const displayName = computed(() => [auth.user?.title, auth.user?.name].filter(Boolean).join(' ') || 'สมาชิก');
const initial = computed(() => (auth.user?.name || '?').trim().charAt(0).toUpperCase());

// บัญชีที่สมัครผ่านโซเชียลโดยไม่มีอีเมลจริงได้อีเมลสังเคราะห์ …@social.local — ไม่ควรโชว์ให้ลูกค้าเห็น
const realEmail = computed(() => {
  const email = String(auth.user?.email || '');
  return email.toLowerCase().endsWith('@social.local') ? '' : email;
});

const maskedIdCard = computed(() => {
  const id = auth.user?.id_card || '';
  if (id.length !== 13) return id;
  return `${id[0]}-XXXX-XXXXX-${id[11]}-${id[12]}`;
});

function displayValue(field) {
  if (field.key === 'email') return realEmail.value;
  if (field.key === 'id_card') return maskedIdCard.value;
  return String(auth.user?.[field.key] ?? '').trim();
}

// ดึงค่าจาก auth.user ทุกครั้งที่เริ่มแก้ — ค่าตอนเปิดหน้ามาจาก localStorage ซึ่งอาจเก่ากว่าที่ /auth/me เพิ่งส่งมา
// ถ้าไม่ sync ตรงนี้ การกดบันทึกจะเขียนค่าเก่าทับของจริง
async function startEdit(focusKey) {
  form.value = snapshotUser();
  fieldErrors.value = {};
  editMode.value = true;
  if (typeof focusKey === 'string') {
    await nextTick();
    const el = document.getElementById(`f-${focusKey}`);
    el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el?.focus({ preventScroll: true });
  }
}

function cancelEdit() {
  editMode.value = false;
  fieldErrors.value = {};
  form.value = snapshotUser();
}

function validateProfile() {
  const errors = {};
  if (!String(form.value.name || '').trim()) errors.name = 'กรุณากรอกชื่อ-นามสกุล';
  if (form.value.id_card && form.value.id_card.length !== 13) errors.id_card = 'เลขบัตรประชาชนต้องมี 13 หลัก';
  fieldErrors.value = errors;
  const first = Object.keys(errors)[0];
  if (first) document.getElementById(`f-${first}`)?.focus();
  return !first;
}

async function saveProfile() {
  if (!validateProfile()) return;
  saving.value = true;
  try {
    // ส่งทุกช่องเสมอ รวมช่องที่ลบจนว่าง — หลังบ้านแปลงสตริงว่างเป็น null แล้วล้างค่าให้
    // (เดิมข้ามช่องว่างไป ทำให้ลบชื่อเล่น/ข้อมูลสุขภาพที่เคยกรอกออกไม่ได้)
    const payload = Object.fromEntries(EDITABLE_KEYS.map((key) => [key, String(form.value[key] ?? '').trim()]));
    const res = await api.post('/auth/profile', payload);
    storeUser(res.data?.data);
    editMode.value = false;
    toast.success('บันทึกข้อมูลเรียบร้อยแล้ว');
  } catch (e) {
    applyServerErrors(e, 'บันทึกข้อมูลไม่สำเร็จ กรุณาลองใหม่');
  } finally {
    saving.value = false;
  }
}

function applyServerErrors(e, fallback) {
  const data = e?.response?.data;
  if (data?.errors) {
    fieldErrors.value = Object.fromEntries(Object.entries(data.errors).map(([k, v]) => [k, v[0]]));
    toast.error(Object.values(data.errors)[0][0]);
  } else {
    toast.error(data?.message || fallback);
  }
}

function storeUser(user) {
  if (!user) return;
  auth.user = user;
  localStorage.setItem('auth_user', JSON.stringify(user));
}

// ─── Avatar ───
const avatarFile = ref(null);
const avatarPreview = ref(null);
const uploadingAvatar = ref(false);

function handleAvatarChange(e) {
  const file = e.target.files?.[0];
  // Reset input so the same file can be re-selected if cancelled
  e.target.value = '';
  if (!file) return;
  if (!file.type.startsWith('image/')) {
    toast.error('กรุณาเลือกไฟล์รูปภาพ');
    return;
  }
  if (file.size > AVATAR_MAX_BYTES) {
    toast.error('รูปต้องมีขนาดไม่เกิน 5MB');
    return;
  }
  if (avatarPreview.value) URL.revokeObjectURL(avatarPreview.value);
  avatarFile.value = file;
  avatarPreview.value = URL.createObjectURL(file);
}

function cancelAvatarUpload() {
  if (avatarPreview.value) URL.revokeObjectURL(avatarPreview.value);
  avatarFile.value = null;
  avatarPreview.value = null;
}

async function uploadAvatar() {
  if (!avatarFile.value) return;
  uploadingAvatar.value = true;
  try {
    const fd = new FormData();
    fd.append('avatar', avatarFile.value);
    const res = await api.post('/auth/profile', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
    storeUser(res.data?.data);
    cancelAvatarUpload();
    toast.success('เปลี่ยนรูปโปรไฟล์แล้ว');
  } catch (e) {
    toast.error(e?.response?.data?.errors?.avatar?.[0] || 'อัปโหลดรูปไม่สำเร็จ กรุณาลองอีกครั้ง');
  } finally {
    uploadingAvatar.value = false;
  }
}

// ─── Password ───
const passwordForm = ref({ password: '', password_confirmation: '' });
const savingPassword = ref(false);
const showPassword = ref(false);

const hasPassword = computed(() => auth.user?.has_password !== false);
const passwordTooShort = computed(() => passwordForm.value.password.length > 0 && passwordForm.value.password.length < PASSWORD_MIN);
const passwordMismatch = computed(() => !!passwordForm.value.password_confirmation && passwordForm.value.password !== passwordForm.value.password_confirmation);
const canSavePassword = computed(() =>
  passwordForm.value.password.length >= PASSWORD_MIN && passwordForm.value.password === passwordForm.value.password_confirmation,
);

const passwordStrength = computed(() => {
  const p = passwordForm.value.password;
  if (!p) return { level: 0, label: '', barColor: '', textColor: '' };
  let score = 0;
  if (p.length >= 8) score++;
  if (p.length >= 12) score++;
  if (/[A-Z]/.test(p)) score++;
  if (/[0-9]/.test(p)) score++;
  if (/[^A-Za-z0-9]/.test(p)) score++;
  if (score <= 1) return { level: Math.max(score, 1), label: 'อ่อนมาก', barColor: 'bg-red-500', textColor: 'text-red-600' };
  if (score === 2) return { level: score, label: 'อ่อน', barColor: 'bg-orange-400', textColor: 'text-orange-600' };
  if (score === 3) return { level: score, label: 'ปานกลาง', barColor: 'bg-amber-400', textColor: 'text-amber-700' };
  if (score === 4) return { level: score, label: 'แข็งแรง', barColor: 'bg-[var(--color-accent-light)]', textColor: 'text-[var(--color-accent)]' };
  return { level: score, label: 'แข็งแรงมาก', barColor: 'bg-[var(--color-accent)]', textColor: 'text-[var(--color-accent)]' };
});

// ส่งเฉพาะรหัสผ่าน — เดิมปุ่มนี้ส่งฟอร์มข้อมูลส่วนตัวไปด้วย ทำให้สิ่งที่พิมพ์ค้างไว้ในแท็บแรกถูกบันทึกโดยไม่ตั้งใจ
// และกดได้แม้ช่องรหัสผ่านว่าง แล้วขึ้นว่า "บันทึกเรียบร้อย" ทั้งที่ไม่ได้เปลี่ยนอะไร
async function savePassword() {
  if (!canSavePassword.value) return;
  savingPassword.value = true;
  try {
    const res = await api.post('/auth/profile', { ...passwordForm.value });
    storeUser(res.data?.data);
    toast.success(hasPassword.value ? 'เปลี่ยนรหัสผ่านแล้ว' : 'ตั้งรหัสผ่านแล้ว');
    passwordForm.value = { password: '', password_confirmation: '' };
    showPassword.value = false;
  } catch (e) {
    const data = e?.response?.data;
    toast.error(data?.errors?.password?.[0] || data?.message || 'เปลี่ยนรหัสผ่านไม่สำเร็จ กรุณาลองใหม่');
  } finally {
    savingPassword.value = false;
  }
}

// ─── Email verification ───
const resendingVerification = ref(false);
const verifyNotice = ref(null);

// Strictly `=== false`, never a falsy check: auth.user is rehydrated from
// localStorage, and anyone signed in from before this field existed has it
// undefined. Treating that as "unverified" would accuse verified customers.
// onMounted refetches the user, at which point the real value arrives.
//
// Social sign-ups without a real address get a synthesised …@social.local one.
// There is nothing to verify there, so nagging them would be a dead end.
const showVerifyBanner = computed(() => {
  const user = auth.user;
  if (!user || user.email_verified !== false) return false;
  return !!realEmail.value;
});

const VERIFY_MESSAGES = {
  success: { tone: 'good', message: 'ยืนยันอีเมลเรียบร้อยแล้วครับ ขอบคุณนะครับ' },
  already: { tone: 'good', message: 'อีเมลนี้ยืนยันไปแล้วครับ' },
  expired: { tone: 'warn', message: 'ลิงก์ยืนยันหมดอายุแล้วครับ กดปุ่มส่งลิงก์ยืนยันเพื่อขอใหม่ได้เลย' },
  invalid: { tone: 'warn', message: 'ลิงก์ยืนยันนี้ใช้ไม่ได้แล้วครับ กดปุ่มส่งลิงก์ยืนยันเพื่อขอใหม่ได้เลย' },
};

// The backend bounces the customer back here with ?verified=… after they click
// the link in their inbox. Read it, then strip it from the URL so a refresh or
// a shared link does not replay the message.
function readVerificationResult() {
  const status = route.query.verified;
  if (!status) return;

  verifyNotice.value = VERIFY_MESSAGES[status] || null;
  router.replace({ query: { ...route.query, verified: undefined } });

  if (status === 'success' || status === 'already') {
    auth.fetchUser();
  }
}

async function resendVerification() {
  resendingVerification.value = true;
  try {
    const res = await auth.resendVerification();
    verifyNotice.value = { tone: 'good', message: res?.message || 'ส่งลิงก์ยืนยันไปที่อีเมลของคุณแล้วครับ' };
  } catch (e) {
    verifyNotice.value = {
      tone: 'warn',
      message: e?.response?.status === 429
        ? 'ขอลิงก์ถี่เกินไปครับ รอสักครู่แล้วลองใหม่อีกครั้ง'
        : (e?.response?.data?.message || 'ส่งลิงก์ไม่สำเร็จ กรุณาลองใหม่อีกครั้งครับ'),
    };
  } finally {
    resendingVerification.value = false;
  }
}

// ─── Public profile ───
const publicProfile = ref({ enabled: false, handle: null, bio: '', url: null });
const savedPublicProfile = ref({ enabled: false, bio: '' });
const savingPublicProfile = ref(false);
const copiedPublicUrl = ref(false);

const publicDirty = computed(() =>
  publicProfile.value.enabled !== savedPublicProfile.value.enabled
  || (publicProfile.value.bio || '') !== (savedPublicProfile.value.bio || ''),
);

function applyPublicProfile(data) {
  if (!data) return;
  publicProfile.value = { ...publicProfile.value, ...data, bio: data.bio || '' };
  savedPublicProfile.value = { enabled: !!data.enabled, bio: data.bio || '' };
}

async function fetchPublicProfile() {
  try {
    const res = await api.get('/me/public-profile');
    applyPublicProfile(res.data?.data);
  } catch {}
}

async function savePublicProfile() {
  savingPublicProfile.value = true;
  try {
    const res = await api.put('/me/public-profile', {
      enabled: publicProfile.value.enabled,
      bio: publicProfile.value.bio?.trim() || null,
    });
    applyPublicProfile(res.data?.data);
    toast.success(res.data?.message || 'บันทึกแล้ว');
  } catch (e) {
    toast.error(e?.response?.data?.message || 'บันทึกไม่สำเร็จ กรุณาลองใหม่');
  } finally {
    savingPublicProfile.value = false;
  }
}

async function copyPublicUrl() {
  try {
    await navigator.clipboard.writeText(publicProfile.value.url);
    copiedPublicUrl.value = true;
    setTimeout(() => { copiedPublicUrl.value = false; }, 2000);
  } catch {
    toast.error('คัดลอกไม่สำเร็จ ลองกดค้างที่ลิงก์แล้วคัดลอกเองได้ครับ');
  }
}

// ─── Loyalty ───
const loyaltyPoints    = ref(null);
const loyaltyTrips     = ref(0);
const loyaltyTierLabel = ref('');
const loyaltyNextTier  = ref(null);

const tierProgress = computed(() => {
  const at = loyaltyNextTier.value?.at;
  if (!at) return 0;
  return Math.min(100, Math.round((loyaltyTrips.value / at) * 100));
});

async function fetchLoyalty() {
  try {
    const res = await api.get('/loyalty/account');
    const account = res.data?.data;
    if (account) {
      loyaltyPoints.value    = account.points ?? 0;
      loyaltyTrips.value     = account.lifetime_trips ?? 0;
      loyaltyTierLabel.value = account.tier_label || '';
      loyaltyNextTier.value  = account.next_tier || null;
    }
  } catch {}
}

// ─── Misc ───
const providerMap = {
  google:   { label: 'Google',   icon: '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>' },
  facebook: { label: 'Facebook', icon: '<svg class="w-4 h-4" fill="#1877F2" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>' },
  line:     { label: 'LINE',     icon: '<svg class="w-4 h-4" fill="#06C755" viewBox="0 0 24 24"><path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.630 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.349 0 .63.285.63.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.281.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.070 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg>' },
};

const providerBadge = computed(() =>
  providerMap[auth.user?.social_provider]
  || { label: 'อีเมลและรหัสผ่าน', icon: '<span class="material-symbols-rounded text-[16px] text-[var(--color-text-muted)]">mail</span>' },
);
const providerName = computed(() => providerMap[auth.user?.social_provider]?.label || 'บัญชีโซเชียล');

function formatJoinDate(iso) {
  if (!iso) return '';
  return new Date(iso).toLocaleDateString('th-TH', { month: 'long', year: 'numeric' });
}

async function handleLogout() {
  if (!confirm('ต้องการออกจากระบบใช่ไหม?')) return;
  await auth.logout();
  router.push('/login');
}

// ออกจากแท็บข้อมูลโดยยังไม่ได้แก้อะไร = ปิดโหมดแก้ไม่ให้ค้าง ถ้าพิมพ์ค้างไว้จะคงไว้ให้กลับมาบันทึกต่อได้
watch(activeTab, () => {
  if (editMode.value && !formDirty.value) cancelEdit();
});

onMounted(() => {
  if (!auth.isLoggedIn) {
    router.push('/login');
    return;
  }
  fetchLoyalty();
  fetchPublicProfile();
  // The cached user in localStorage predates email_verified for anyone already
  // signed in, and goes stale the moment they verify on another device.
  auth.fetchUser();
  readVerificationResult();
});
</script>

<style scoped>
.btn-primary {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.75rem 1.5rem;
  border-radius: 0.875rem;
  font-size: 0.875rem;
  font-weight: 700;
  color: #fff;
  background-color: var(--color-primary);
  transition: background-color 0.2s ease, opacity 0.2s ease;
}
.btn-primary:hover:not(:disabled) { background-color: var(--color-accent); }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }

.btn-ghost {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  padding: 0.5rem 0.875rem;
  border-radius: 0.75rem;
  font-size: 0.8125rem;
  font-weight: 700;
  color: var(--color-text-dark);
  background-color: var(--color-sand);
  border: 1px solid var(--color-sand-dark);
  transition: border-color 0.2s ease, color 0.2s ease;
}
.btn-ghost:hover:not(:disabled) { border-color: var(--color-accent); color: var(--color-accent); }

.field-label {
  display: block;
  margin-bottom: 0.5rem;
  font-size: 0.8125rem;
  font-weight: 700;
  color: var(--color-text-mid);
}
.field-input {
  display: flex;
  align-items: center;
  width: 100%;
  padding: 0.8125rem 1rem;
  border-radius: 0.875rem;
  border: 1.5px solid var(--color-sand-dark);
  background-color: #fff;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-text-dark);
  outline: none;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.field-input::placeholder { color: var(--color-text-muted); opacity: 0.6; font-weight: 500; }
.field-input:focus {
  border-color: var(--color-accent);
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--color-accent) 14%, transparent);
}
.field-input--error { border-color: #ef4444; }
.field-input--readonly {
  gap: 0.5rem;
  background-color: var(--color-sand);
  color: var(--color-text-muted);
  cursor: not-allowed;
}
.field-help {
  margin-top: 0.375rem;
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.side-tab {
  display: flex;
  align-items: center;
  gap: 0.875rem;
  width: 100%;
  padding: 0.75rem 1rem;
  border-radius: 1rem;
  font-size: 0.9375rem;
  font-weight: 700;
  color: var(--color-text-mid);
  transition: background-color 0.2s ease, color 0.2s ease;
}
.side-tab:hover { background-color: var(--color-sand); }
.side-tab--active,
.side-tab--active:hover { background-color: var(--color-primary); color: #fff; }

.side-link {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
  border-radius: 0.875rem;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-text-mid);
  transition: background-color 0.2s ease;
}
.side-link:hover { background-color: var(--color-sand); }

.stat-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 0.875rem 0.5rem;
  transition: background-color 0.2s ease;
}
.stat-cell:hover { background-color: var(--color-sand); }
.stat-cell:first-child { border-radius: 1rem 0 0 1rem; }
.stat-cell:last-child { border-radius: 0 1rem 1rem 0; }
.stat-label { font-size: 0.75rem; font-weight: 700; color: var(--color-text-muted); }
.stat-value {
  margin-top: 0.125rem;
  font-size: 1.375rem;
  font-weight: 800;
  line-height: 1.2;
  color: var(--color-text-dark);
  font-variant-numeric: tabular-nums;
}
.stat-unit { margin-left: 0.125rem; font-size: 0.75rem; font-weight: 700; color: var(--color-text-muted); }

.notice {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.875rem;
  padding: 1rem 1.25rem;
  border-radius: 1.25rem;
  border: 1px solid;
  background-color: #fff;
}
.notice--warn { border-color: #fde68a; }
.notice--good { border-color: color-mix(in srgb, var(--color-accent) 35%, white); }

.switch {
  position: relative;
  width: 2.75rem;
  height: 1.625rem;
  border-radius: 9999px;
  background-color: var(--color-sand-dark);
  transition: background-color 0.2s ease;
}
.switch--on { background-color: var(--color-accent); }
.switch-thumb {
  position: absolute;
  top: 0.1875rem;
  left: 0.1875rem;
  width: 1.25rem;
  height: 1.25rem;
  border-radius: 9999px;
  background-color: #fff;
  transition: transform 0.2s ease;
}
.switch--on .switch-thumb { transform: translateX(1.125rem); }
.switch:focus-visible,
.side-tab:focus-visible,
.stat-cell:focus-visible {
  outline: 2px solid var(--color-accent);
  outline-offset: 2px;
}

.scrollbar-none {
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.scrollbar-none::-webkit-scrollbar {
  display: none;
}
</style>
