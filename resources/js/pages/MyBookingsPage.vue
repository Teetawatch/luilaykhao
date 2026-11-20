<template>
  <div class="min-h-screen bg-[var(--color-sand)] pb-32 font-anuphan relative overflow-hidden">
    <PageHeader
      :title="$t('การจองของฉัน')"
      :subtitle="$t('ดูสถานะการจอง ชำระเงินงวดถัดไป และตั๋วเดินทางของทุกทริปได้ที่นี่')"
      :crumbs="[{ to: '/profile', label: $t('บัญชีของฉัน') }]"
      container="max-w-4xl px-4 sm:px-6"
    >
      <template #actions>
        <router-link to="/trips" class="ph-btn ph-btn--solid">
          <span class="material-symbols-rounded icon-18">explore</span>
          {{ $t('จองทริปใหม่') }}
        </router-link>
      </template>
    </PageHeader>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 pt-8 relative z-10">

      <!-- คิวรอที่นั่งของฉัน -->
      <MyWaitlist />

      <!-- Tabs -->
      <div class="flex gap-1.5 mb-8 bg-white/70 backdrop-blur-sm p-1.5 rounded-[16px] w-fit border border-[#E8EEEF]">
        <button
          @click="activeTab = 'upcoming'"
          class="px-5 py-2.5 text-sm font-bold rounded-[12px] transition-all duration-300 flex items-center gap-2"
          :class="activeTab === 'upcoming'
            ? 'bg-[var(--color-accent)] text-white'
            : 'text-[#505E5E] hover:text-[var(--color-accent)] hover:bg-[#F4F7F6]'">
          <span class="material-symbols-rounded text-[20px]" :style="activeTab === 'upcoming' ? 'font-variation-settings:\'FILL\' 1' : 'font-variation-settings:\'FILL\' 0'">event_upcoming</span>
          {{ $t('ที่กำลังจะมาถึง') }}
          <span v-if="upcomingCount" class="min-w-[20px] h-5 px-1.5 rounded-full text-[11px] font-black flex items-center justify-center"
            :class="activeTab === 'upcoming' ? 'bg-white/25 text-white' : 'bg-[#E8EEEF] text-[#505E5E]'">{{ upcomingCount }}</span>
        </button>
        <button
          @click="activeTab = 'past'"
          class="px-5 py-2.5 text-sm font-bold rounded-[12px] transition-all duration-300 flex items-center gap-2"
          :class="activeTab === 'past'
            ? 'bg-[var(--color-accent)] text-white'
            : 'text-[#505E5E] hover:text-[var(--color-accent)] hover:bg-[#F4F7F6]'">
          <span class="material-symbols-rounded text-[20px]" :style="activeTab === 'past' ? 'font-variation-settings:\'FILL\' 1' : 'font-variation-settings:\'FILL\' 0'">history</span>
          {{ $t('ที่ผ่านมาแล้ว') }}
          <span v-if="pastCount" class="min-w-[20px] h-5 px-1.5 rounded-full text-[11px] font-black flex items-center justify-center"
            :class="activeTab === 'past' ? 'bg-white/25 text-white' : 'bg-[#E8EEEF] text-[#505E5E]'">{{ pastCount }}</span>
        </button>
      </div>

      <!-- Loading -->
      <div v-if="bookingStore.loading" class="flex flex-col items-center justify-center py-24 space-y-4">
        <div class="w-10 h-10 border-4 border-[var(--color-accent)]/20 border-t-[var(--color-accent)] rounded-full animate-spin"></div>
        <p class="text-[#505E5E] font-medium animate-pulse text-sm">{{ $t('กำลังโหลดข้อมูลการจอง...') }}</p>
      </div>

      <!-- Empty State -->
      <div v-else-if="filteredBookings.length === 0" class="text-center py-20 bg-white rounded-[28px] border border-[#E8EEEF] relative overflow-hidden">
        <div class="relative z-10 flex flex-col items-center px-4">
          <div class="w-24 h-24 bg-[#F4F7F6] rounded-full flex items-center justify-center mb-5 border border-[#E8EEEF]">
            <span class="material-symbols-rounded text-[44px] text-[#8FA5A5]">
              {{ activeTab === 'upcoming' ? 'event_busy' : 'history_toggle_off' }}
            </span>
          </div>
          <h3 class="text-lg font-bold text-[#1a1c1c] mb-2">{{ $t('ยังไม่มีการจอง') }}</h3>
          <p class="text-[#505E5E] text-sm mb-6 max-w-sm mx-auto">
            {{ activeTab === 'upcoming' 
                ? $t('คุณยังไม่มีแผนการเดินทางที่กำลังจะมาถึง เริ่มค้นหาประสบการณ์ใหม่ๆ ได้เลย!') 
                : $t('คุณยังไม่เคยเดินทางกับเรามาก่อน ลองดูทริปที่น่าสนใจสิ') }}
          </p>
          <router-link to="/trips"
             class="inline-flex items-center gap-2 bg-[var(--color-accent)] text-white px-6 py-3 rounded-full font-bold text-sm hover:bg-[var(--color-accent-mid)] transition-all">
            <span class="material-symbols-rounded text-[20px]">explore</span>
            {{ $t('เริ่มค้นหากิจกรรม') }}
            <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
          </router-link>
        </div>
      </div>

      <!-- Booking Cards -->
      <div v-else class="space-y-4">
        <article
          v-for="b in filteredBookings"
          :key="b.id"
          class="bg-white rounded-[24px] overflow-hidden flex flex-col md:flex-row group border border-[#E8EEEF] transition-all duration-300 hover:border-[var(--color-accent)]/25 hover:-translate-y-0.5 relative"
          :class="{ 'opacity-80': b.status === 'cancelled' || b.status === 'refunded' }">

          <div class="absolute top-0 left-0 w-1.5 h-full bg-[var(--color-accent)] z-20" v-if="b.status === 'confirmed'"></div>
          <div class="absolute top-0 left-0 w-1.5 h-full bg-[#D97706] z-20" v-if="b.status === 'pending'"></div>

          <!-- Image -->
          <div class="md:w-[260px] h-52 md:h-auto relative overflow-hidden shrink-0"
            :class="{ 'grayscale opacity-75': b.status === 'cancelled' || b.status === 'refunded' }">
            <img
              :src="b.schedule.trip.thumbnail_image || b.schedule.trip.cover_image"
              :alt="b.schedule?.trip?.title"
              class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110" />
            <div class="absolute inset-0 bg-gradient-to-t from-black/40 via-transparent to-transparent md:bg-gradient-to-r md:from-transparent md:to-black/5"></div>

            <!-- Date Badge on Image -->
            <div class="absolute top-4 left-4 bg-white/95 backdrop-blur-sm px-3.5 py-2 rounded-[14px] flex flex-col items-center leading-none">
              <span class="text-[10px] font-bold text-[var(--color-accent)] uppercase tracking-wide mb-0.5">{{ getMonthShort(b.schedule?.departure_date) }}</span>
              <span class="text-xl font-extrabold text-[#1a1c1c]">{{ getDay(b.schedule?.departure_date) }}</span>
            </div>

            <!-- Title overlay (mobile) -->
            <h2 class="absolute bottom-4 left-4 right-4 md:hidden text-white text-lg font-bold leading-snug line-clamp-2">
              {{ b.schedule?.trip?.title || $t('การจอง') }}
            </h2>
          </div>

          <!-- Content -->
          <div class="p-5 md:p-6 flex-1 flex flex-col relative w-full">
            <div class="flex flex-col sm:flex-row justify-between items-start mb-3 gap-3">
              <h2 class="hidden md:block text-lg font-bold text-[#1a1c1c] leading-snug line-clamp-2 md:mr-8 transition-colors group-hover:text-[var(--color-accent)]">
                {{ b.schedule?.trip?.title || $t('การจอง') }}
              </h2>
              <div class="flex flex-col items-start sm:items-end gap-2 shrink-0 w-full sm:w-auto">
                <span class="px-2.5 py-1 text-xs font-bold rounded-[8px] flex items-center gap-1.5 whitespace-nowrap"
                  :class="awaitsNewRound(b) ? statusClass('pending') : statusClass(b.status)">
                  <span class="w-1.5 h-1.5 rounded-full" :class="awaitsNewRound(b) ? statusDotClass('pending') : statusDotClass(b.status)"></span>
                  {{ displayStatusLabel(b) }}
                </span>
                <CountdownTimer
                  v-if="isPendingWithTimer(b)"
                  :seconds="seatsStore.countdownSeconds"
                  :total-seconds="600"
                  class="text-xs w-full" />
              </div>
            </div>

            <div class="space-y-3 mb-5 bg-[#F9FAFA] p-3.5 rounded-[16px] border border-[#E8EEEF]">
              <div class="flex items-center justify-between text-[13px] text-[#505E5E]">
                <div class="flex items-center gap-2.5">
                  <div class="w-8 h-8 rounded-full bg-white flex items-center justify-center border border-[#E8EEEF] shrink-0">
                    <span class="material-symbols-rounded text-[16px] text-[var(--color-accent)]">calendar_month</span>
                  </div>
                  <span class="font-medium text-[#1a1c1c]" :class="{ 'line-through text-[#889696]': awaitsNewRound(b) }">{{ formatDate(b.schedule?.departure_date) }}</span>
                  <span v-if="awaitsNewRound(b)" class="text-[11px] font-bold text-[#B45309]">{{ $t('รอบนี้ยกเลิก') }}</span>
                </div>
                <div class="text-right shrink-0">
                  <span class="text-[10px] text-[#889696] font-bold block mb-0.5 uppercase tracking-wider">{{ $t('หมายเลขการจอง') }}</span>
                  <span class="font-bold text-[#1a1c1c] font-mono">{{ b.booking_ref }}</span>
                </div>
              </div>
            </div>

            <!-- Price & Installment Info -->
            <div class="mb-5">
              <div class="flex justify-between items-end">
                <div class="text-[11px] font-bold text-[#889696] uppercase tracking-wide">{{ $t('ยอดชำระ') }}</div>
                <div class="text-xl md:text-2xl font-bold text-[var(--color-accent)] tracking-tight">
                  <span class="text-sm text-[var(--color-accent)] mr-0.5">{{ $t('฿') }}</span>{{ Number(b.total_amount).toLocaleString() }}
                </div>
              </div>

              <!-- แผนผ่อนชำระ — component เดียวกับหน้ายืนยันการจอง -->
              <InstallmentPlanPanel
                v-if="b.payment_type === 'installment' && b.installment_payments?.length"
                :booking-ref="b.booking_ref"
                :installments="b.installment_payments"
                :installment-count="b.installment_count"
                class="mt-4" />

              <!-- แบ่งจ่ายกลุ่ม — มัดจำที่ยังมียอดคงเหลือค้างชำระ -->
              <SplitPaymentPanel
                v-if="b.status === 'confirmed' && b.payment_type === 'deposit' && !b.balance_paid_at && Number(b.balance_amount) > 0"
                :booking-ref="b.booking_ref"
                class="mt-4" />
            </div>

            <!-- รอบเดิมถูกยกเลิก (เหตุสุดวิสัย หรือผู้ร่วมทริปไม่ครบ) — สิทธิ์เลือกรอบใหม่ -->
            <div v-if="awaitsNewRound(b)" class="mb-5 rounded-[16px] bg-[#FFFBEB] border border-[#FDE68A] p-4">
              <p class="font-bold text-[#78350F] text-sm flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 1">{{ isUnderfilled(b) ? 'groups' : 'thunderstorm' }}</span>
                {{ isUnderfilled(b) ? $t('รอบเดินทางนี้ไม่ได้ออก') : $t('รอบเดินทางนี้ออกไม่ได้') }}<template v-if="b.force_majeure.reason"> {{ $t('เนื่องจาก{reason}', { reason: b.force_majeure.reason }) }}</template>
              </p>
              <template v-if="b.force_majeure.can_choose">
                <p v-if="isUnderfilled(b)" class="text-[13px] text-[#92400E] mt-1.5 leading-relaxed">
                  {{ $t('เลือกรอบใหม่ของทริปนี้ได้ฟรี ราคาเดิม หรือขอรับเงินคืนเต็มจำนวน') }}
                  <template v-if="b.force_majeure.refund_amount > 0">{{ $t('฿{Number}', { Number: Number(b.force_majeure.refund_amount).toLocaleString($dateLocale) }) }}</template>
                  {{ $t('ภายใน') }} <strong>{{ b.force_majeure.decide_by_label }}</strong>
                  <template v-if="b.force_majeure.days_left != null"> {{ $t('(เหลือ {days_left} วัน)', { days_left: b.force_majeure.days_left }) }}</template>
                </p>
                <p v-else class="text-[13px] text-[#92400E] mt-1.5 leading-relaxed">
                  {{ $t('ยอดที่ชำระไว้ยังอยู่ครบ เลือกรอบใหม่ของทริปนี้ได้ฟรี ราคาเดิม ภายใน') }} <strong>{{ b.force_majeure.until_label }}</strong>
                  <template v-if="b.force_majeure.days_left != null"> {{ $t('(เหลือ {days_left} วัน)', { days_left: b.force_majeure.days_left }) }}</template>
                </p>
                <p v-if="b.viewer_is_owner === false" class="text-[12px] text-[#92400E] mt-2">{{ $t('ผู้จองเป็นคนเลือกรอบใหม่ให้ทั้งกลุ่มครับ') }}</p>
                <div v-else class="mt-3 flex flex-col sm:flex-row gap-2">
                  <button v-if="b.can_reschedule" @click="openReschedule(b)"
                    class="w-full sm:w-auto bg-[#D97706] text-white py-2.5 px-5 rounded-[12px] font-bold text-sm hover:bg-[#B45309] transition-all inline-flex items-center justify-center gap-1.5">
                    <span class="material-symbols-rounded text-[18px]">event_repeat</span>
                    {{ $t('เลือกรอบใหม่') }}
                  </button>
                  <button v-if="b.force_majeure.can_request_refund" @click="openRefund(b)"
                    class="w-full sm:w-auto bg-white text-[#B45309] border border-[#FCD34D] py-2.5 px-5 rounded-[12px] font-bold text-sm hover:bg-[#FFFBEB] transition-all inline-flex items-center justify-center gap-1.5">
                    <span class="material-symbols-rounded text-[18px]">currency_exchange</span>
                    {{ b.force_majeure.refund_amount > 0 ? $t('ขอรับเงินคืน') : $t('ยกเลิกการจอง') }}
                  </button>
                </div>
              </template>
              <template v-else-if="isUnderfilled(b)">
                <p class="text-[13px] text-[#92400E] mt-1.5 leading-relaxed">
                  {{ $t('เลยกำหนดเลือกรอบใหม่แล้ว ({decide_by_label}) เราจะคืนเงินเต็มจำนวนให้ครับ กรอกบัญชีรับเงินไว้ได้เลย จะได้เร็วขึ้น', { decide_by_label: b.force_majeure.decide_by_label }) }}
                </p>
                <button v-if="b.force_majeure.can_request_refund && b.viewer_is_owner !== false" @click="openRefund(b)"
                  class="mt-3 w-full sm:w-auto bg-[#D97706] text-white py-2.5 px-5 rounded-[12px] font-bold text-sm hover:bg-[#B45309] transition-all inline-flex items-center justify-center gap-1.5">
                  <span class="material-symbols-rounded text-[18px]">currency_exchange</span>
                  {{ b.force_majeure.refund_amount > 0 ? $t('กรอกบัญชีรับเงินคืน') : $t('ยกเลิกการจอง') }}
                </button>
              </template>
              <p v-else class="text-[13px] text-[#92400E] mt-1.5 leading-relaxed">
                {{ $t('เลยกำหนดเลือกรอบใหม่แล้ว ({until_label}) ทักทีมงานเพื่อช่วยดูแลต่อได้เลยครับ', { until_label: b.force_majeure.until_label }) }}
              </p>
            </div>

            <!-- รอบคนไม่ครบ — ขอคืนเงินแล้ว/โอนคืนแล้ว -->
            <div v-else-if="refundState(b)" class="mb-5 rounded-[16px] bg-[#F0FAFA] border border-[#BCDFDF] p-4 text-[13px] text-[#0F3D3E]">
              <p class="font-bold text-sm flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[18px]">currency_exchange</span>
                {{ refundState(b) === 'refunded' ? $t('โอนเงินคืนแล้ว') : $t('รับเรื่องคืนเงินแล้ว') }}
                <template v-if="b.force_majeure.refund_amount > 0">{{ $t('· ฿{Number}', { Number: Number(b.force_majeure.refund_amount).toLocaleString($dateLocale) }) }}</template>
              </p>
              <p v-if="refundState(b) === 'refund_requested'" class="mt-1 leading-relaxed">
                <template v-if="b.force_majeure.refund_account_label">{{ $t('โอนเข้า {refund_account_label} ภายใน 3–7 วันทำการครับ', { refund_account_label: b.force_majeure.refund_account_label }) }}</template>
                <template v-else>{{ $t('ทีมงานจะติดต่อขอเลขบัญชีรับเงินคืนครับ') }}</template>
              </p>
            </div>

            <!-- Actions -->
            <div class="mt-auto flex gap-2.5 flex-wrap sm:flex-nowrap">
              <router-link
                v-if="b.status !== 'pending'"
                :to="`/confirmation/${b.booking_ref}`"
                class="flex-1 text-center bg-[var(--color-accent)] text-white py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-[var(--color-accent-mid)] transition-all flex items-center justify-center gap-1.5">
                <span v-if="b.status === 'confirmed'" class="material-symbols-rounded text-[18px]">confirmation_number</span>
                <span v-else class="material-symbols-rounded text-[18px]">visibility</span>
                {{ b.status === 'confirmed' ? $t('ดาวน์โหลดตั๋ว') : $t('ดูรายละเอียด') }}
              </router-link>

              <!-- ปุ่มจ่ายเงินขึ้นเฉพาะ pending ที่ยังไม่ได้ส่งสลิป หรือถูกปฏิเสธให้ส่งใหม่
                   — ไม่ขึ้นสำหรับรายการที่ "กำลังตรวจสอบยอด" (จ่ายมาแล้ว รอแอดมินยืนยัน) -->
              <router-link
                v-if="b.status === 'pending' && reviewState(b) !== 'under_review'"
                :to="`/payment/${b.booking_ref}`"
                class="flex-1 text-center bg-[#D97706] text-white py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-[#B45309] transition-all flex items-center justify-center gap-1.5 animate-pulse">
                <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 1">payments</span>
                {{ reviewState(b) === 'rejected' ? $t('ส่งสลิปใหม่') : $t('ชำระเงิน') }}
              </router-link>

              <!-- เฉพาะสถานะ pending: ปุ่มหลักคือ "ชำระเงิน" จึงต้องมีทางไปดูรายละเอียด
                   แยกไว้ ส่วนสถานะอื่นปุ่มหลักพาไปหน้าเดียวกันนี้อยู่แล้ว -->
              <router-link
                v-if="b.status === 'pending'"
                :to="`/confirmation/${b.booking_ref}`"
                class="flex-1 text-center bg-white text-[#505E5E] border border-[#E8EEEF] hover:bg-[#F9FAFA] py-2.5 px-4 rounded-[12px] font-bold text-sm transition-all flex items-center justify-center gap-1.5">
                <span class="material-symbols-rounded text-[18px]">info</span>
                {{ $t('รายละเอียด') }}
              </router-link>

              <!-- สรุปทริป — ขึ้นเฉพาะรอบที่จบแล้ว เพราะ recap ต้องรอวันสุดท้ายผ่านไป -->
              <router-link
                v-if="activeTab === 'past' && (b.status === 'completed' || b.status === 'confirmed')"
                :to="`/recap/${b.booking_ref}`"
                class="flex-1 text-center border-2 border-[#0F3D3E] text-[#0F3D3E] py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-[#E3F2F2] transition-all flex items-center justify-center gap-1.5">
                <span class="material-symbols-rounded text-[18px]">auto_awesome</span>
                {{ $t('สรุปทริป') }}
              </router-link>

              <router-link
                v-if="activeTab === 'past' && b.status === 'completed'"
                to="/my-reviews"
                class="flex-1 text-center border-2 border-[var(--color-accent)] text-[var(--color-accent)] py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-[#E3F2F2] transition-all flex items-center justify-center gap-1.5">
                <span class="material-symbols-rounded text-[18px]">star</span>
                {{ $t('เขียนรีวิว') }}
              </router-link>

              <router-link
                v-if="b.status === 'confirmed'"
                :to="{ name: 'trip-chat', params: { scheduleId: b.schedule.id }, query: { title: b.schedule?.trip?.title, date: b.schedule?.departure_date } }"
                class="flex-1 text-center border-2 border-[var(--color-accent)] text-[var(--color-accent)] py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-[#E3F2F2] transition-all flex items-center justify-center gap-1.5">
                <span class="material-symbols-rounded text-[18px]">chat</span>
                {{ $t('แชท') }}
              </router-link>

              <button
                v-if="canReviewStaff(b)"
                @click="openStaffReviewModal(b)"
                class="flex-1 text-center border-2 border-[#0C4A6E] text-[#0C4A6E] py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-[#E0F2FE] transition-all flex items-center justify-center gap-1.5">
                <span class="material-symbols-rounded text-[18px]">badge</span>
                {{ $t('รีวิวสตาฟ') }}
              </button>
              
              <button
                v-if="b.status === 'pending'"
                @click="handleCancel(b)"
                class="flex-1 sm:flex-none border border-[#FCA5A5] text-[#DC2626] py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-[#FEF2F2] hover:border-[#F87171] transition-all">
                {{ $t('ยกเลิก') }}
              </button>

              <button
                v-if="b.can_reschedule && !awaitsNewRound(b) && b.viewer_is_owner !== false"
                @click="openReschedule(b)"
                class="flex-1 sm:flex-none bg-white text-[var(--color-accent)] border border-[#BCDFDF] hover:bg-[#F0FAFA] py-2.5 px-4 rounded-[12px] font-bold text-sm transition-all flex items-center justify-center gap-1.5">
                <span class="material-symbols-rounded text-[18px]">event_repeat</span>
                {{ $t('เปลี่ยนวันเดินทาง') }}
              </button>

              <button
                v-if="b.permit_sign_required"
                @click="permitTarget = b"
                class="flex-1 sm:flex-none bg-[#FFFBEB] text-[#92400E] border border-[#FDE68A] hover:bg-[#FEF3C7] py-2.5 px-4 rounded-[12px] font-bold text-sm transition-all flex items-center justify-center gap-1.5">
                <span class="material-symbols-rounded text-[18px]">signature</span>
                {{ $t('เซ็นแบบฟอร์มอุทยาน') }}
              </button>

              <button
                v-if="b.seat_handover?.available"
                @click="handoverTarget = b"
                class="flex-1 sm:flex-none bg-white text-[var(--color-accent)] border border-[#BCDFDF] hover:bg-[#F0FAFA] py-2.5 px-4 rounded-[12px] font-bold text-sm transition-all flex items-center justify-center gap-1.5">
                <span class="material-symbols-rounded text-[18px]">swap_horiz</span>
                {{ $t('ส่งต่อที่นั่ง') }}
                <span v-if="b.seat_handover.open_count > 0"
                  class="ml-0.5 text-[11px] font-black bg-[#FFFBEB] text-[#B45309] border border-[#FDE68A] rounded-full px-1.5">
                  {{ $t('รอรับ {open_count}', { open_count: b.seat_handover.open_count }) }}
                </span>
              </button>

              <button
                v-if="b.status === 'confirmed' && !awaitsNewRound(b)"
                @click="addToCalendar(b)"
                class="flex-1 sm:flex-none bg-white text-[#505E5E] border border-[#E8EEEF] hover:bg-[#F9FAFA] py-2.5 px-4 rounded-[12px] font-bold text-sm transition-all flex items-center justify-center gap-1.5">
                <span class="material-symbols-rounded text-[18px]">event</span>
                {{ $t('เพิ่มลงปฏิทิน') }}
              </button>

              <button
                v-if="canShareTracking(b)"
                @click="openShareTracking(b)"
                class="flex-1 sm:flex-none bg-white text-[#505E5E] border border-[#E8EEEF] hover:bg-[#F9FAFA] py-2.5 px-4 rounded-[12px] font-bold text-sm transition-all flex items-center justify-center gap-1.5">
                <span class="material-symbols-rounded text-[18px]">share_location</span>
                {{ $t('แชร์ให้ที่บ้านติดตาม') }}
              </button>

              <button
                v-if="isOngoingTrip(b)"
                @click="openSosModal(b)"
                class="flex-1 sm:flex-none bg-red-600 text-white py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-red-700 active:scale-95 transition-all flex items-center justify-center gap-1.5">
                <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 1">sos</span>
                SOS
              </button>
            </div>
          </div>
        </article>

        <!-- Pagination -->
        <nav v-if="lastPage > 1" class="flex justify-center items-center mt-8 gap-2" :aria-label="$t('หน้าของรายการจอง')">
          <button
            :disabled="currentPage === 1"
            @click="load(currentPage - 1)"
            class="w-9 h-9 rounded-[10px] bg-white border border-[#E8EEEF] text-[#505E5E] hover:bg-[#F9FAFA] disabled:opacity-40 disabled:hover:bg-white flex items-center justify-center"
            :aria-label="$t('หน้าก่อนหน้า')">
            <span class="material-symbols-rounded text-[18px]">chevron_left</span>
          </button>

          <template v-for="(page, i) in pageNumbers" :key="`${page}-${i}`">
            <span v-if="page === '…'" class="w-6 text-center text-[#889696] font-bold">…</span>
            <button
              v-else
              @click="load(page)"
              :aria-current="page === currentPage ? 'page' : undefined"
              class="w-9 h-9 rounded-[10px] text-sm font-bold transition-all"
              :class="page === currentPage
                ? 'bg-[var(--color-accent)] text-white'
                : 'bg-white border border-[#E8EEEF] text-[#505E5E] hover:bg-[#F9FAFA]'">
              {{ page }}
            </button>
          </template>

          <button
            :disabled="currentPage === lastPage"
            @click="load(currentPage + 1)"
            class="w-9 h-9 rounded-[10px] bg-white border border-[#E8EEEF] text-[#505E5E] hover:bg-[#F9FAFA] disabled:opacity-40 disabled:hover:bg-white flex items-center justify-center"
            :aria-label="$t('หน้าถัดไป')">
            <span class="material-symbols-rounded text-[18px]">chevron_right</span>
          </button>
        </nav>
      </div>

      <!-- Staff Review Modal -->
      <div v-if="showStaffReviewModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 px-4" @click.self="showStaffReviewModal = false">
        <div class="bg-white w-full max-w-lg rounded-2xl border border-[#E8EEEF] overflow-hidden">
          <div class="px-5 py-4 border-b border-[#E8EEEF] flex items-center justify-between">
            <div>
              <h3 class="text-lg font-bold text-[#1a1c1c]">{{ $t('รีวิวสตาฟประจำทริป') }}</h3>
              <p class="text-xs text-[#6b7280] mt-0.5">{{ reviewingBooking?.schedule?.trip?.title || '-' }}</p>
            </div>
            <button @click="showStaffReviewModal = false" class="w-8 h-8 rounded-lg border border-[#E5E7EB] hover:bg-[#F3F4F6] inline-flex items-center justify-center">
              <span class="material-symbols-rounded text-[18px]">close</span>
            </button>
          </div>

          <div class="p-5 space-y-4">
            <div>
              <label class="block text-sm font-semibold text-[#334155] mb-1">{{ $t('เลือกสตาฟ') }}</label>
              <select v-model.number="staffReviewForm.staff_user_id" @change="hydrateStaffReviewForm" class="w-full rounded-xl border border-[#D7E0E1] px-3 py-2.5 text-sm">
                <option :value="0" disabled>{{ $t('-- เลือกสตาฟ --') }}</option>
                <option v-for="staff in reviewingBooking?.assigned_staff || []" :key="staff.id" :value="staff.id">
                  {{ staff.name }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-semibold text-[#334155] mb-1">{{ $t('คะแนนความพึงพอใจ') }}</label>
              <div class="grid grid-cols-5 gap-2">
                <button
                  v-for="score in [1,2,3,4,5]"
                  :key="score"
                  type="button"
                  @click="staffReviewForm.rating = score"
                  class="h-10 rounded-xl border text-sm font-bold transition"
                  :class="staffReviewForm.rating === score ? 'bg-[var(--color-accent)] text-white border-[var(--color-accent)]' : 'bg-white text-[#475569] border-[#D7E0E1] hover:bg-[#F8FAFC]'"
                >
                  {{ score }} ★
                </button>
              </div>
            </div>

            <div>
              <label class="block text-sm font-semibold text-[#334155] mb-1">{{ $t('ความคิดเห็น (ไม่บังคับ)') }}</label>
              <textarea
                v-model="staffReviewForm.comment"
                rows="4"
                class="w-full rounded-xl border border-[#D7E0E1] px-3 py-2.5 text-sm"
                :placeholder="$t('เล่าประสบการณ์การดูแลของสตาฟคนนี้')"
              ></textarea>
            </div>

            <div v-if="selectedStaffReview" class="rounded-xl bg-[#F8FAFC] border border-[#E2E8F0] p-3 text-xs text-[#475569]">
              {{ $t('คุณเคยรีวิวสตาฟคนนี้แล้ว ระบบจะอัปเดตรีวิวเดิม') }}
            </div>
          </div>

          <div class="px-5 py-4 border-t border-[#E8EEEF] flex justify-end gap-2">
            <button @click="showStaffReviewModal = false" class="px-4 py-2.5 rounded-xl border border-[#D7E0E1] text-sm font-semibold text-[#475569] hover:bg-[#F8FAFC]">{{ $t('ยกเลิก') }}</button>
            <button
              @click="submitStaffReview"
              :disabled="reviewSubmitting || !staffReviewForm.staff_user_id"
              class="px-4 py-2.5 rounded-xl bg-[var(--color-accent)] text-white text-sm font-bold hover:bg-[var(--color-accent-mid)] disabled:opacity-60"
            >
              {{ selectedStaffReview ? $t('อัปเดตรีวิวสตาฟ') : $t('ส่งรีวิวสตาฟ') }}
            </button>
          </div>
        </div>
      </div>

    </div>

    <!-- SOS Modal -->
    <Teleport to="body">
      <div v-if="showSosModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 px-4 pb-4 sm:pb-0" @click.self="showSosModal = false">
        <div class="bg-white w-full max-w-md rounded-[24px] overflow-hidden">
          <!-- Header -->
          <div class="bg-red-600 px-5 py-4 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center">
              <span class="material-symbols-rounded text-white text-[22px]" style="font-variation-settings:'FILL' 1">sos</span>
            </div>
            <div class="flex-1">
              <h3 class="text-white font-black text-base">{{ $t('ขอความช่วยเหลือ SOS') }}</h3>
              <p class="text-red-100 text-xs">{{ sosBooking?.schedule?.trip?.title }}</p>
            </div>
            <button @click="showSosModal = false" class="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center transition-all">
              <span class="material-symbols-rounded text-white text-[18px]">close</span>
            </button>
          </div>

          <div class="p-5 space-y-4">
            <p class="text-sm text-[#505E5E]">{{ $t('เลือกข้อความที่ต้องการส่งให้สตาฟและผู้ร่วมทริป') }}</p>

            <!-- Predefined options -->
            <div class="grid grid-cols-2 gap-2">
              <button
                v-for="opt in sosOptions"
                :key="opt.value"
                @click="selectSosOption(opt)"
                class="py-3 px-4 rounded-[14px] text-sm font-bold border-2 transition-all active:scale-95 text-left"
                :class="sosSelectedOption === opt.value
                  ? 'bg-red-50 border-red-500 text-red-700'
                  : 'bg-[#F4F7F6] border-[#E8EEEF] text-[#1a1c1c] hover:border-red-300 hover:bg-red-50/50'">
                <span class="block text-lg mb-0.5">{{ opt.emoji }}</span>
                {{ opt.label }}
              </button>
            </div>

            <!-- Custom message (shown when "อื่น ๆ" selected) -->
            <div v-if="sosSelectedOption === 'other'" class="space-y-1.5">
              <label class="text-xs font-bold text-[#505E5E] uppercase tracking-wide">{{ $t('ระบุเพิ่มเติม') }}</label>
              <textarea
                v-model="sosCustomMessage"
                rows="3"
                maxlength="255"
                :placeholder="$t('อธิบายสถานการณ์โดยย่อ...')"
                class="w-full rounded-[14px] border-2 border-[#E8EEEF] focus:border-red-400 focus:outline-none px-3.5 py-2.5 text-sm resize-none"
              ></textarea>
            </div>

            <p class="text-xs text-[#889696] bg-[#F4F7F6] p-3 rounded-[12px]">
              <span class="material-symbols-rounded text-[14px] align-middle mr-1" style="font-variation-settings:'FILL' 1">info</span>
              {{ $t('สตาฟและผู้โดยสารในทริปจะได้รับการแจ้งเตือนทันที') }}
            </p>
          </div>

          <div class="px-5 pb-5 flex gap-2">
            <button @click="showSosModal = false" class="flex-1 py-3 rounded-[14px] border-2 border-[#E8EEEF] text-sm font-bold text-[#505E5E] hover:bg-[#F4F7F6] transition-all">{{ $t('ยกเลิก') }}</button>
            <button
              @click="submitSos"
              :disabled="!sosSelectedOption || sosSubmitting || (sosSelectedOption === 'other' && !sosCustomMessage.trim())"
              class="flex-1 py-3 rounded-[14px] bg-red-600 text-white font-black text-sm hover:bg-red-700 active:scale-95 transition-all disabled:opacity-50 flex items-center justify-center gap-2">
              <span v-if="sosSubmitting" class="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin"></span>
              <span class="material-symbols-rounded text-[18px]" v-else style="font-variation-settings:'FILL' 1">sos</span>
              {{ sosSubmitting ? $t('กำลังส่ง...') : $t('ส่งสัญญาณ SOS') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <RescheduleModal
      v-if="rescheduleTarget"
      :booking="rescheduleTarget"
      @close="rescheduleTarget = null"
      @refund="switchToRefund"
      @done="onRescheduled" />

    <PermitSignModal
      v-if="permitTarget"
      :booking="permitTarget"
      @close="permitTarget = null"
    />

    <SeatHandoverModal
      v-if="handoverTarget"
      :booking="handoverTarget"
      @close="handoverTarget = null"
      @changed="load(currentPage)" />

    <PostponementRefundModal
      v-if="refundTarget"
      :booking="refundTarget"
      @close="refundTarget = null"
      @done="onRefundRequested" />

  </div>
</template>

<script setup>
import { $t } from '@/i18n';
import { DATE_LOCALE } from '@/i18n';
import PageHeader from '../components/PageHeader.vue';
import { ref, computed, onMounted, watch } from 'vue';
import { useBookingStore } from '../stores/booking';
import { useSeatsStore } from '../stores/seats';
import api from '../lib/axios';
import { useSwal } from '../lib/swal';
import { useToast } from '../lib/toast';
import { bangkokToday, daysUntil } from '../lib/bangkokDate';
import CountdownTimer from '../components/CountdownTimer.vue';
import MyWaitlist from '../components/MyWaitlist.vue';
import SplitPaymentPanel from '../components/SplitPaymentPanel.vue';
import InstallmentPlanPanel from '../components/InstallmentPlanPanel.vue';
import RescheduleModal from '../components/RescheduleModal.vue';
import PostponementRefundModal from '../components/PostponementRefundModal.vue';
import SeatHandoverModal from '../components/SeatHandoverModal.vue';
import PermitSignModal from '../components/PermitSignModal.vue';
import { useRoute, useRouter } from 'vue-router';

const swal = useSwal();
const toast = useToast();
const route = useRoute();
const router = useRouter();

/** จำนวนการจองต่อหน้า — การ์ดสูงพอสมควร 8 ใบกำลังพอดีหนึ่งหน้าจอเลื่อน */
const PER_PAGE = 8;

const bookingStore = useBookingStore();
const seatsStore = useSeatsStore();
const activeTab = ref('upcoming');
const showStaffReviewModal = ref(false);
const reviewSubmitting = ref(false);
const reviewingBooking = ref(null);

// SOS
const showSosModal = ref(false);
const sosBooking = ref(null);
const sosSelectedOption = ref('');
const sosCustomMessage = ref('');
const sosSubmitting = ref(false);

const sosOptions = [
  { value: 'help', label: $t('ช่วยด้วย'), emoji: '🆘' },
  { value: 'lost', label: $t('ฉันหลงทาง'), emoji: '🗺️' },
  { value: 'worried', label: $t('ฉันกังวล'), emoji: '😟' },
  { value: 'unsafe', label: $t('ฉันรู้สึกไม่ปลอดภัย'), emoji: '⚠️' },
  { value: 'other', label: $t('อื่น ๆ'), emoji: '💬' },
];

const sosMessageMap = {
  help: $t('ช่วยด้วย'),
  lost: $t('ฉันหลงทาง'),
  worried: $t('ฉันกังวล'),
  unsafe: $t('ฉันรู้สึกไม่ปลอดภัย'),
};
const staffReviewForm = ref({
  staff_user_id: 0,
  rating: 5,
  comment: '',
});

const selectedStaffReview = computed(() => {
  if (!reviewingBooking.value || !staffReviewForm.value.staff_user_id) return null;

  return (reviewingBooking.value.staff_reviews || []).find(
    (review) => Number(review.staff_user_id) === Number(staffReviewForm.value.staff_user_id),
  ) || null;
});

function isPendingWithTimer(b) {
  return b.status === 'pending'
    && !b.slip_ocr_status // ส่งสลิปแล้ว = ไม่อยู่บนนาฬิกาจ่ายเงินอีกต่อไป
    && seatsStore.activeBookingInfo?.scheduleId == b.schedule?.id
    && seatsStore.countdownSeconds > 0;
}

// การจองที่ส่งสลิปแล้วแต่ยอดไม่ตรง (status ยัง pending): 'under_review' รอแอดมินยืนยัน,
// 'rejected' ถูกปฏิเสธต้องส่งสลิปใหม่, null = pending ปกติที่ยังไม่จ่าย
function reviewState(b) {
  if (b.status !== 'pending' || !b.slip_ocr_status) return null;
  return b.slip_ocr_status === 'rejected' ? 'rejected' : 'under_review';
}
/** รอบเดิมถูกยกเลิก (เหตุสุดวิสัย/คนไม่ครบ) และยังไม่ได้เลือกรอบใหม่ */
function awaitsNewRound(b) {
  return !!b.force_majeure?.awaiting;
}

/** รอบเดิมไม่ได้ออกเพราะผู้ร่วมทริปไม่ครบ — เลือกรับเงินคืนแทนได้ */
function isUnderfilled(b) {
  return b.force_majeure?.kind === 'underfilled';
}

/** ขอคืนเงินแล้ว (refund_requested) หรือโอนคืนแล้ว (refunded) — null ถ้าไม่ใช่ */
function refundState(b) {
  const state = b.force_majeure?.state;
  return isUnderfilled(b) && (state === 'refund_requested' || state === 'refunded') ? state : null;
}

function displayStatusLabel(b) {
  if (awaitsNewRound(b)) return $t('รอเลือกรอบใหม่');
  const rs = reviewState(b);
  if (rs === 'under_review') return $t('กำลังตรวจสอบยอด');
  if (rs === 'rejected') return $t('ต้องส่งสลิปใหม่');
  return statusLabel(b.status);
}

// การกรองและการนับย้ายไปฝั่งเซิร์ฟเวอร์แล้ว หน้านี้จึงแสดงสิ่งที่ได้มาตรง ๆ
const filteredBookings = computed(() => bookingStore.bookings);

const upcomingCount = computed(() => bookingStore.meta?.upcoming_count ?? 0);
const pastCount = computed(() => bookingStore.meta?.past_count ?? 0);

const currentPage = computed(() => bookingStore.meta?.current_page ?? 1);
const lastPage = computed(() => bookingStore.meta?.last_page ?? 1);

/**
 * เลขหน้าที่จะแสดง — ย่อเป็นช่วงรอบหน้าปัจจุบันพร้อม … เมื่อมีหลายหน้า
 * ไม่งั้น 20 หน้าก็จะได้ปุ่ม 20 ปุ่มเรียงกัน
 */
const pageNumbers = computed(() => {
  const total = lastPage.value;
  const current = currentPage.value;

  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);

  const pages = new Set([1, total, current]);
  if (current - 1 > 1) pages.add(current - 1);
  if (current + 1 < total) pages.add(current + 1);

  const sorted = [...pages].sort((a, b) => a - b);
  const withGaps = [];

  sorted.forEach((page, i) => {
    if (i > 0 && page - sorted[i - 1] > 1) withGaps.push('…');
    withGaps.push(page);
  });

  return withGaps;
});

function load(page = 1) {
  return bookingStore.fetchMyBookings({ page, perPage: PER_PAGE, scope: activeTab.value });
}

// สลับแท็บ = ถามเซิร์ฟเวอร์ใหม่ และกลับไปหน้าแรกเสมอ
watch(activeTab, () => load(1));

const statusMap = {
  pending:   $t('รอชำระเงิน'),
  confirmed: $t('ยืนยันแล้ว'),
  cancelled: $t('ยกเลิกแล้ว'),
  refunded:  $t('คืนเงินแล้ว'),
  completed: $t('เสร็จสิ้นแล้ว'),
};

function statusLabel(s) { return statusMap[s] || s; }

function statusClass(s) {
  const map = {
    pending:   'bg-[#FFFAF0] text-[#D97706] border border-[#FDE68A]',
    confirmed: 'bg-[#F0FAFA] text-[var(--color-accent)] border border-[#BCDFDF]',
    cancelled: 'bg-[#F4F7F6] text-[#505E5E] border border-[#E8EEEF]',
    refunded:  'bg-[#F4F7F6] text-[#505E5E] border border-[#E8EEEF]',
    completed: 'bg-[#EFF6FF] text-[#2563EB] border border-[#BFDBFE]',
  };
  return map[s] || 'bg-[#F4F7F6] text-[#505E5E] border border-[#E8EEEF]';
}

function statusDotClass(s) {
  const map = {
    pending:   'bg-[#D97706]',
    confirmed: 'bg-[var(--color-accent)]',
    cancelled: 'bg-[#A0B0B0]',
    refunded:  'bg-[#A0B0B0]',
    completed: 'bg-[#2563EB]',
  };
  return map[s] || 'bg-[#A0B0B0]';
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString(DATE_LOCALE, { day: 'numeric', month: 'long', year: 'numeric' });
}

function formatShortDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString(DATE_LOCALE, { day: 'numeric', month: 'short' });
}

function getDay(d) {
  if (!d) return '';
  return new Date(d).getDate();
}

function getMonthShort(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString(DATE_LOCALE, { month: 'short' });
}

function canReviewStaff(booking) {
  return booking.status === 'completed' && (booking.assigned_staff?.length || 0) > 0;
}

function openStaffReviewModal(booking) {
  reviewingBooking.value = booking;
  showStaffReviewModal.value = true;

  const firstStaffId = booking.assigned_staff?.[0]?.id || 0;
  staffReviewForm.value = {
    staff_user_id: firstStaffId,
    rating: 5,
    comment: '',
  };

  hydrateStaffReviewForm();
}

function hydrateStaffReviewForm() {
  const existing = selectedStaffReview.value;

  if (!existing) {
    staffReviewForm.value.rating = 5;
    staffReviewForm.value.comment = '';
    return;
  }

  staffReviewForm.value.rating = Number(existing.rating) || 5;
  staffReviewForm.value.comment = existing.comment || '';
}

async function submitStaffReview() {
  if (!reviewingBooking.value || !staffReviewForm.value.staff_user_id) return;

  reviewSubmitting.value = true;
  try {
    await api.post('/staff/reviews', {
      booking_id: reviewingBooking.value.id,
      staff_user_id: staffReviewForm.value.staff_user_id,
      rating: staffReviewForm.value.rating,
      comment: staffReviewForm.value.comment || null,
    });

    showStaffReviewModal.value = false;
    await load(currentPage.value);
    toast.success($t('บันทึกรีวิวสตาฟเรียบร้อยแล้ว'));
  } catch (e) {
    toast.error(e?.response?.data?.message || $t('ส่งรีวิวสตาฟไม่สำเร็จ'));
  } finally {
    reviewSubmitting.value = false;
  }
}

function isOngoingTrip(b) {
  if (b.status !== 'confirmed' || awaitsNewRound(b)) return false;
  const departure = b.schedule?.departure_date;
  const returnDate = b.schedule?.return_date;
  if (!departure) return false;
  // ต้องเทียบด้วยวันที่ไทย ไม่ใช่ UTC ไม่งั้นตี 5 ของวันออกเดินทาง (= เมื่อวานตาม UTC)
  // ปุ่ม SOS จะยังไม่ขึ้น ทั้งที่เป็นช่วงที่คนกำลังเดินทางจริง
  const today = bangkokToday();
  const end = returnDate || departure;
  return today >= departure && today <= end;
}

/**
 * ลิงก์ติดตามใช้ได้เฉพาะช่วงใกล้เดินทางจริง — โชว์ตั้งแต่ 2 วันก่อนออกเดินทาง
 * จนถึงวันสุดท้ายของทริป ไม่งั้นคนที่บ้านจะเปิดไปเจอแผนที่เปล่า ๆ
 */
function canShareTracking(b) {
  if (b.status !== 'confirmed' || awaitsNewRound(b)) return false;

  const days = daysUntil(b.schedule?.departure_date);
  if (days === null) return false;

  const end = b.schedule?.return_date || b.schedule?.departure_date;

  return days <= 2 && bangkokToday() <= end;
}

async function openShareTracking(b) {
  try {
    // token ถูกสร้างฝั่งเซิร์ฟเวอร์เมื่อเรียกครั้งแรก จึงต้องถามทุกครั้งแทนการเดา URL เอง
    const res = await api.get(`/bookings/${b.booking_ref}/tracking`);
    const url = res.data?.data?.share_url;

    if (!url) {
      toast.error($t('ยังไม่มีลิงก์ติดตามสำหรับการจองนี้'));
      return;
    }

    const { isConfirmed } = await swal.confirm({
      title: $t('แชร์ให้ที่บ้านติดตาม'),
      html: $t('<p style="margin-bottom:8px">ส่งลิงก์นี้ให้คนที่บ้านดูตำแหน่งรถได้แบบไม่ต้องล็อกอิน</p>\n             <code style="word-break:break-all;font-size:13px">{url}</code>', { url }),
      icon: 'info',
      confirmText: $t('คัดลอกลิงก์'),
      cancelText: $t('ปิด'),
    });

    if (!isConfirmed) return;

    await navigator.clipboard.writeText(url);
    toast.success($t('คัดลอกลิงก์แล้ว'));
  } catch (e) {
    toast.error(e?.response?.data?.message || $t('ขอลิงก์ติดตามไม่สำเร็จ'));
  }
}

/**
 * ไฟล์ .ics ดาวน์โหลดฝั่งเบราว์เซอร์ ไม่ต้องผ่านเซิร์ฟเวอร์
 * ใช้ DTSTART/DTEND แบบ VALUE=DATE (all-day) เพราะเรารู้แค่วันที่ ไม่รู้เวลาออกเดินทางแน่นอน
 */
function addToCalendar(b) {
  const start = b.schedule?.departure_date;

  if (!start) {
    toast.error($t('การจองนี้ยังไม่มีวันเดินทาง'));
    return;
  }

  const compact = (d) => d.replaceAll('-', '');
  // DTEND ของ all-day event เป็นแบบ exclusive จึงต้องบวกอีกหนึ่งวัน
  const endDate = new Date(`${b.schedule?.return_date || start}T00:00:00Z`);
  endDate.setUTCDate(endDate.getUTCDate() + 1);

  const escape = (text) => String(text || '').replace(/([,;\\])/g, '\\$1').replace(/\n/g, '\\n');

  const ics = [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//luilaykhao//booking//TH',
    'BEGIN:VEVENT',
    `UID:${b.booking_ref}@luilaykhao.com`,
    `DTSTART;VALUE=DATE:${compact(start)}`,
    `DTEND;VALUE=DATE:${compact(endDate.toISOString().slice(0, 10))}`,
    `SUMMARY:${escape(b.schedule?.trip?.title || $t('ทริปลุยเลเขา'))}`,
    `LOCATION:${escape(b.schedule?.trip?.location)}`,
    `DESCRIPTION:${escape($t('หมายเลขการจอง {booking_ref}', { booking_ref: b.booking_ref }))}`,
    'END:VEVENT',
    'END:VCALENDAR',
  ].join('\r\n');

  const link = document.createElement('a');
  link.href = URL.createObjectURL(new Blob([ics], { type: 'text/calendar;charset=utf-8' }));
  link.download = `${b.booking_ref}.ics`;
  link.click();
  URL.revokeObjectURL(link.href);

  toast.success($t('ดาวน์โหลดไฟล์ปฏิทินแล้ว'));
}

function openSosModal(b) {
  sosBooking.value = b;
  sosSelectedOption.value = '';
  sosCustomMessage.value = '';
  showSosModal.value = true;
}

function selectSosOption(opt) {
  sosSelectedOption.value = opt.value;
  if (opt.value !== 'other') sosCustomMessage.value = '';
}

async function submitSos() {
  if (!sosBooking.value || !sosSelectedOption.value) return;

  const message = sosSelectedOption.value === 'other'
    ? sosCustomMessage.value.trim()
    : sosMessageMap[sosSelectedOption.value];

  sosSubmitting.value = true;
  try {
    await api.post('/sos', {
      schedule_id: sosBooking.value.schedule.id,
      message,
    });
    showSosModal.value = false;
    // SOS ต้องยืนยันแบบที่ผู้ใช้ต้องกดรับรู้ ไม่ใช่ toast ที่หายไปเอง
    await swal.success($t('ส่งสัญญาณ SOS แล้ว'), $t('สตาฟและผู้ร่วมทริปได้รับการแจ้งเตือนแล้ว'));
  } catch (e) {
    swal.error($t('ส่ง SOS ไม่สำเร็จ'), e?.response?.data?.message || $t('กรุณาลองใหม่อีกครั้ง'));
  } finally {
    sosSubmitting.value = false;
  }
}

async function handleCancel(b) {
  const { isConfirmed } = await swal.confirm({
    title: $t('ยกเลิกการจองนี้?'),
    text: `${b.schedule?.trip?.title || $t('การจอง')} · ${b.booking_ref}`,
    icon: 'warning',
    confirmText: $t('ยกเลิกการจอง'),
    cancelText: $t('ไม่ใช่ตอนนี้'),
  });

  if (!isConfirmed) return;

  try {
    await bookingStore.cancelBooking(b.booking_ref, 'ยกเลิกโดยลูกค้า');
    await load(currentPage.value);
    toast.success($t('ยกเลิกการจองแล้ว'));
  } catch (e) {
    toast.error(e?.response?.data?.message || $t('ยกเลิกไม่สำเร็จ'));
  }
}

// ── เปลี่ยนวันเดินทาง / เลือกรอบใหม่ ─────────────────────────────────────
const rescheduleTarget = ref(null);

// ส่งต่อที่นั่ง — ปุ่มขึ้นเมื่อเซิร์ฟเวอร์บอกว่าผู้เรียกส่งต่อได้ (booking.seat_handover.available)
const handoverTarget = ref(null);
// เซ็นแบบฟอร์มอุทยาน — ปุ่มขึ้นเมื่อทริปให้เซ็น (booking.permit_sign_required)
const permitTarget = ref(null);

function openReschedule(b) {
  rescheduleTarget.value = b;
}

// ── ขอรับเงินคืน (รอบคนไม่ครบ) ────────────────────────────────────────
const refundTarget = ref(null);

function openRefund(b) {
  refundTarget.value = b;
}

function switchToRefund() {
  refundTarget.value = rescheduleTarget.value;
  rescheduleTarget.value = null;
}

async function onRefundRequested(updated) {
  refundTarget.value = null;
  await load(currentPage.value);
  const amount = Number(updated?.force_majeure?.refund_amount || 0);
  swal.success(
    amount > 0 ? $t('รับเรื่องคืนเงินแล้ว') : $t('ยกเลิกการจองแล้ว'),
    amount > 0 ? $t('เราจะโอนคืน ฿{toLocaleString} ภายใน 3–7 วันทำการครับ', { toLocaleString: amount.toLocaleString(DATE_LOCALE) }) : '',
  );
}

async function onRescheduled(updated) {
  rescheduleTarget.value = null;
  const date = updated?.schedule?.departure_date;
  await load(currentPage.value);
  swal.success($t('ย้ายรอบเรียบร้อยแล้ว'), date ? $t('รอบใหม่ของคุณคือวันที่ {formatDate}', { formatDate: formatDate(date) }) : '');
}

/**
 * ลิงก์จากอีเมล/SMS "รอบต้องเลื่อน" (/my-bookings?reschedule=REF) — เปิดหน้าต่าง
 * เลือกรอบให้เลย ใบนั้นอาจไม่อยู่หน้าแรกของรายการจึงถามเซิร์ฟเวอร์ตรง ๆ
 */
async function openFromLink() {
  const ref = String(route.query.reschedule || '').trim();
  if (!ref) return;

  router.replace({ query: { ...route.query, reschedule: undefined } });

  try {
    const res = await api.get(`/bookings/${encodeURIComponent(ref)}`);
    const booking = res.data?.data;
    if (booking?.can_reschedule && booking.viewer_is_owner !== false) {
      openReschedule(booking);
    } else if (booking?.force_majeure?.can_request_refund && booking.viewer_is_owner !== false) {
      // คนไม่ครบที่เลยกำหนดเลือกรอบ — ทางเดียวที่เหลือคือกรอกบัญชีรับเงินคืน
      openRefund(booking);
    } else if (booking?.force_majeure?.expired) {
      swal.warning($t('เลยกำหนดเลือกรอบใหม่แล้ว'), $t('ทักทีมงานเพื่อช่วยดูแลต่อได้เลยครับ'));
    } else if (booking && refundState(booking)) {
      toast.success($t('การจองนี้ขอรับเงินคืนเรียบร้อยแล้ว'));
    } else if (booking?.force_majeure?.state === 'moved') {
      toast.success($t('การจองนี้เลือกรอบใหม่เรียบร้อยแล้ว'));
    }
  } catch {
    toast.error($t('ไม่พบการจองนี้ในบัญชีของคุณ'));
  }
}

onMounted(async () => {
  await load(1);
  openFromLink();
});
</script>
