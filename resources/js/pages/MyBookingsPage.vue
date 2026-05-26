<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32 font-anuphan">
    <div class="max-w-4xl mx-auto px-4 sm:px-6">

      <!-- Page Header -->
      <section class="mb-8 relative">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-2" style="font-family:'Anuphan',sans-serif;">
          การจองของฉัน
        </h1>
        <p class="text-[#505E5E] text-sm" style="font-family:'Anuphan',sans-serif;">
          จัดการแผนการเดินทางที่แสนพิเศษของคุณได้ที่นี่
        </p>
      </section>

      <!-- Tabs -->
      <div class="flex gap-2 mb-8 bg-[#E8EEEF] p-1.5 rounded-[16px] w-fit shadow-inner">
        <button
          @click="activeTab = 'upcoming'"
          class="px-5 py-2.5 text-sm font-bold rounded-[12px] transition-all duration-300 flex items-center gap-2"
          :class="activeTab === 'upcoming'
            ? 'bg-white text-[#006565] shadow-sm'
            : 'text-[#505E5E] hover:text-[#006565] hover:bg-white/40'"
          style="font-family: 'Anuphan', sans-serif;">
          <span class="material-symbols-rounded text-[20px]" :style="activeTab === 'upcoming' ? 'font-variation-settings:\'FILL\' 1' : 'font-variation-settings:\'FILL\' 0'">event_upcoming</span>
          ที่กำลังจะมาถึง
        </button>
        <button
          @click="activeTab = 'past'"
          class="px-5 py-2.5 text-sm font-bold rounded-[12px] transition-all duration-300 flex items-center gap-2"
          :class="activeTab === 'past'
            ? 'bg-white text-[#006565] shadow-sm'
            : 'text-[#505E5E] hover:text-[#006565] hover:bg-white/40'"
          style="font-family: 'Anuphan', sans-serif;">
          <span class="material-symbols-rounded text-[20px]" :style="activeTab === 'past' ? 'font-variation-settings:\'FILL\' 1' : 'font-variation-settings:\'FILL\' 0'">history</span>
          ที่ผ่านมาแล้ว
        </button>
      </div>

      <!-- Loading -->
      <div v-if="bookingStore.loading" class="flex flex-col items-center justify-center py-24 space-y-4">
        <div class="w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
        <p class="text-[#505E5E] font-medium animate-pulse text-sm" style="font-family: 'Anuphan', sans-serif;">กำลังโหลดข้อมูลการจอง...</p>
      </div>

      <!-- Empty State -->
      <div v-else-if="filteredBookings.length === 0" class="text-center py-20 bg-white rounded-[24px] shadow-sm border border-[#E8EEEF] relative overflow-hidden">
        <div class="relative z-10 flex flex-col items-center px-4">
          <div class="w-20 h-20 bg-[#F4F7F6] rounded-full flex items-center justify-center mb-5">
            <span class="material-symbols-rounded text-4xl text-[#A0B0B0]">
              {{ activeTab === 'upcoming' ? 'event_busy' : 'history_toggle_off' }}
            </span>
          </div>
          <h3 class="text-lg font-bold text-[#1a1c1c] mb-2" style="font-family: 'Anuphan', sans-serif;">ยังไม่มีการจอง</h3>
          <p class="text-[#505E5E] text-sm mb-6 max-w-sm mx-auto" style="font-family: 'Anuphan', sans-serif;">
            {{ activeTab === 'upcoming' 
                ? 'คุณยังไม่มีแผนการเดินทางที่กำลังจะมาถึง เริ่มค้นหาประสบการณ์ใหม่ๆ ได้เลย!' 
                : 'คุณยังไม่เคยเดินทางกับเรามาก่อน ลองดูทริปที่น่าสนใจสิ' }}
          </p>
          <router-link to="/trips"
             class="inline-flex items-center gap-2 bg-[#006565] text-white px-6 py-3 rounded-full font-bold text-sm hover:bg-[#004f4f] transition-all"
             style="font-family: 'Anuphan', sans-serif;">
            <span class="material-symbols-rounded text-[20px]">explore</span>
            เริ่มค้นหากิจกรรม
            <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
          </router-link>
        </div>
      </div>

      <!-- Booking Cards -->
      <div v-else class="space-y-4">
        <article
          v-for="b in filteredBookings"
          :key="b.id"
          class="bg-white rounded-[20px] overflow-hidden flex flex-col md:flex-row group border border-[#E8EEEF] shadow-sm transition-all duration-300 hover:shadow-md hover:border-[#006565]/30 relative"
          :class="{ 'opacity-80': b.status === 'cancelled' || b.status === 'refunded' }">
          
          <div class="absolute top-0 left-0 w-1.5 h-full bg-[#006565] z-10" v-if="b.status === 'confirmed'"></div>
          <div class="absolute top-0 left-0 w-1.5 h-full bg-[#D97706] z-10" v-if="b.status === 'pending'"></div>

          <!-- Image -->
          <div class="md:w-[240px] h-48 md:h-auto relative overflow-hidden shrink-0"
            :class="{ 'grayscale opacity-75': b.status === 'cancelled' || b.status === 'refunded' }">
            <img
              :src="b.schedule.trip.thumbnail_image || b.schedule.trip.cover_image"
              :alt="b.schedule?.trip?.title"
              class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" />
            
            <!-- Date Badge on Image (Mobile only) -->
            <div class="absolute top-4 left-4 md:hidden bg-white px-3 py-1.5 rounded-[12px] shadow-sm flex flex-col items-center leading-tight">
              <span class="text-[10px] font-bold text-[#889696] uppercase" style="font-family: 'Anuphan', sans-serif;">{{ getMonthShort(b.schedule?.departure_date) }}</span>
              <span class="text-base font-extrabold text-[#1a1c1c]">{{ getDay(b.schedule?.departure_date) }}</span>
            </div>
          </div>

          <!-- Content -->
          <div class="p-5 md:p-6 flex-1 flex flex-col relative w-full">
            <div class="flex flex-col sm:flex-row justify-between items-start mb-3 gap-3">
              <h2 class="text-lg font-bold text-[#1a1c1c] leading-snug line-clamp-2 md:mr-8 transition-colors group-hover:text-[#006565]" style="font-family:'Anuphan',sans-serif;">
                {{ b.schedule?.trip?.title || 'การจอง' }}
              </h2>
              <div class="flex flex-col items-end gap-2 shrink-0">
                <span class="px-2.5 py-1 text-xs font-bold rounded-[8px] flex items-center gap-1.5 whitespace-nowrap"
                  :class="statusClass(b.status)"
                  style="font-family:'Anuphan',sans-serif;">
                  <span class="w-1.5 h-1.5 rounded-full" :class="statusDotClass(b.status)"></span>
                  {{ statusLabel(b.status) }}
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
                <div class="flex items-center gap-2.5" style="font-family:'Anuphan',sans-serif;">
                  <div class="w-8 h-8 rounded-full bg-white flex items-center justify-center border border-[#E8EEEF] shrink-0">
                    <span class="material-symbols-rounded text-[16px] text-[#006565]">calendar_month</span>
                  </div>
                  <span class="font-medium text-[#1a1c1c]">{{ formatDate(b.schedule?.departure_date) }}</span>
                </div>
                <div class="text-right shrink-0" style="font-family:'Anuphan',sans-serif;">
                  <span class="text-[10px] text-[#889696] font-bold block mb-0.5 uppercase tracking-wider">หมายเลขการจอง</span>
                  <span class="font-bold text-[#1a1c1c]">{{ b.booking_ref }}</span>
                </div>
              </div>
            </div>

            <!-- Price & Installment Info -->
            <div class="mb-5">
              <div class="flex justify-between items-end" style="font-family:'Anuphan',sans-serif;">
                <div class="text-[11px] font-bold text-[#889696] uppercase tracking-wide">ยอดชำระ</div>
                <div class="text-xl md:text-2xl font-bold text-[#006565] tracking-tight">
                  <span class="text-sm text-[#006565] mr-0.5">฿</span>{{ Number(b.total_amount).toLocaleString() }}
                </div>
              </div>

              <!-- ── Installment Tracker ── -->
              <div v-if="b.payment_type === 'installment' && b.installment_payments?.length" class="mt-4 p-4 bg-gradient-to-br from-amber-50 to-orange-50/30 rounded-[16px] border border-amber-100 space-y-4">
                
                <!-- Header -->
                <div class="flex items-center justify-between">
                  <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-lg bg-amber-500 flex items-center justify-center shadow-sm">
                      <span class="material-symbols-rounded text-white text-[16px]">calendar_month</span>
                    </div>
                    <span class="text-sm font-black text-amber-900">ผ่อนชำระ {{ b.installment_count }} งวด</span>
                  </div>
                  <span class="text-[10px] font-black text-amber-600 bg-amber-100 px-2.5 py-1 rounded-full uppercase tracking-tight">
                    {{ getPaidInstallments(b).length }} / {{ b.installment_count }} งวด
                  </span>
                </div>

                <!-- Progress Bar -->
                <div class="relative">
                  <div class="h-2.5 bg-amber-100 rounded-full overflow-hidden">
                    <div 
                      class="h-full bg-gradient-to-r from-amber-400 to-amber-500 rounded-full transition-all duration-700 ease-out"
                      :style="{ width: (getPaidInstallments(b).length / b.installment_count * 100) + '%' }"
                    ></div>
                  </div>
                </div>

                <!-- Installment Steps -->
                <div class="flex gap-1.5">
                  <div 
                    v-for="inst in b.installment_payments" 
                    :key="inst.installment_no"
                    class="flex-1 flex flex-col items-center gap-1.5 py-2 px-1 rounded-xl transition-all"
                    :class="{
                      'bg-green-50 border border-green-200': inst.status === 'paid',
                      'bg-amber-100/50 border border-amber-200 ring-2 ring-amber-300/50': inst.status === 'pending' && isNextDue(b, inst),
                      'bg-white/50 border border-gray-100': inst.status === 'pending' && !isNextDue(b, inst),
                    }"
                  >
                    <div 
                      class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-black"
                      :class="{
                        'bg-green-500 text-white': inst.status === 'paid',
                        'bg-amber-500 text-white animate-pulse': inst.status === 'pending' && isNextDue(b, inst),
                        'bg-gray-200 text-gray-500': inst.status === 'pending' && !isNextDue(b, inst),
                      }"
                    >
                      <span v-if="inst.status === 'paid'" class="material-symbols-rounded text-[14px]">check</span>
                      <span v-else>{{ inst.installment_no }}</span>
                    </div>
                    <span class="text-[9px] font-bold text-center leading-tight"
                      :class="inst.status === 'paid' ? 'text-green-700' : 'text-gray-500'"
                    >
                      {{ inst.status === 'paid' ? 'ชำระแล้ว' : formatShortDate(inst.due_date) }}
                    </span>
                    <span class="text-[10px] font-black" :class="inst.status === 'paid' ? 'text-green-600' : 'text-gray-700'">
                      ฿{{ Number(inst.amount).toLocaleString() }}
                    </span>
                  </div>
                </div>

                <!-- Next Due Alert -->
                <div v-if="getNextPendingInstallment(b)" class="flex items-center justify-between p-3 rounded-xl border-2 border-dashed transition-all"
                  :class="isOverdue(getNextPendingInstallment(b))
                    ? 'bg-red-50 border-red-300'
                    : isDueSoon(getNextPendingInstallment(b))
                      ? 'bg-amber-50 border-amber-300'
                      : 'bg-white border-gray-200'"
                >
                  <div class="flex items-center gap-2.5">
                    <span class="material-symbols-rounded text-[18px]" 
                      :class="isOverdue(getNextPendingInstallment(b)) ? 'text-red-500' : 'text-amber-600'"
                      :style="isOverdue(getNextPendingInstallment(b)) ? 'font-variation-settings:\'FILL\' 1' : ''"
                    >
                      {{ isOverdue(getNextPendingInstallment(b)) ? 'warning' : 'schedule' }}
                    </span>
                    <div>
                      <p class="text-[11px] font-black uppercase tracking-wide"
                        :class="isOverdue(getNextPendingInstallment(b)) ? 'text-red-700' : 'text-amber-800'"
                      >
                        {{ isOverdue(getNextPendingInstallment(b)) ? '⚠ เลยกำหนดชำระแล้ว!' : 'งวดถัดไป: งวดที่ ' + getNextPendingInstallment(b).installment_no }}
                      </p>
                      <p class="text-[10px] font-bold" :class="isOverdue(getNextPendingInstallment(b)) ? 'text-red-600' : 'text-amber-700'">
                        กำหนด {{ formatDate(getNextPendingInstallment(b).due_date) }}
                        <span v-if="!isOverdue(getNextPendingInstallment(b))" class="text-amber-500"> · {{ getDaysUntil(getNextPendingInstallment(b).due_date) }}</span>
                      </p>
                    </div>
                  </div>
                  <button
                    @click.stop="goToInstallmentPayment(b)"
                    class="px-4 py-2 rounded-xl text-xs font-black transition-all active:scale-95 flex items-center gap-1.5 shadow-sm"
                    :class="isOverdue(getNextPendingInstallment(b))
                      ? 'bg-red-500 text-white hover:bg-red-600 shadow-red-500/20'
                      : 'bg-amber-500 text-white hover:bg-amber-600 shadow-amber-500/20'"
                  >
                    <span class="material-symbols-rounded text-[14px]">payments</span>
                    ชำระงวด
                  </button>
                </div>

                <!-- All Paid -->
                <div v-else-if="getPaidInstallments(b).length === b.installment_count" class="flex items-center gap-2 p-3 bg-green-50 rounded-xl border border-green-200">
                  <span class="material-symbols-rounded text-green-600 text-[18px]" style="font-variation-settings:'FILL' 1">verified</span>
                  <span class="text-xs font-black text-green-700">ชำระครบทุกงวดเรียบร้อยแล้ว ✓</span>
                </div>
              </div>
            </div>

            <!-- Actions -->
            <div class="mt-auto flex gap-2.5 flex-wrap sm:flex-nowrap">
              <router-link
                v-if="b.status !== 'pending'"
                :to="`/confirmation/${b.booking_ref}`"
                class="flex-1 text-center bg-[#006565] text-white py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-[#004f4f] transition-all flex items-center justify-center gap-1.5"
                style="font-family:'Anuphan',sans-serif;">
                <span v-if="b.status === 'confirmed'" class="material-symbols-rounded text-[18px]">confirmation_number</span>
                <span v-else class="material-symbols-rounded text-[18px]">visibility</span>
                {{ b.status === 'confirmed' ? 'ดาวน์โหลดตั๋ว' : 'ดูรายละเอียด' }}
              </router-link>

              <router-link
                v-if="b.status === 'pending'"
                :to="`/payment/${b.booking_ref}`"
                class="flex-1 text-center bg-[#D97706] text-white py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-[#B45309] transition-all flex items-center justify-center gap-1.5 animate-pulse"
                style="font-family:'Anuphan',sans-serif;">
                <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 1">payments</span>
                ชำระเงิน
              </router-link>

              <button
                v-if="b.status === 'confirmed' || b.status === 'pending'"
                @click="$router.push(`/confirmation/${b.booking_ref}`)"
                class="flex-1 bg-white text-[#505E5E] border border-[#E8EEEF] hover:bg-[#F9FAFA] py-2.5 px-4 rounded-[12px] font-bold text-sm transition-all flex items-center justify-center gap-1.5"
                style="font-family:'Anuphan',sans-serif;">
                <span class="material-symbols-rounded text-[18px]">info</span>
                รายละเอียด
              </button>
              
              <router-link
                v-if="activeTab === 'past' && b.status === 'completed'"
                to="/my-reviews"
                class="flex-1 text-center border-2 border-[#006565] text-[#006565] py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-[#E3F2F2] transition-all flex items-center justify-center gap-1.5"
                style="font-family:'Anuphan',sans-serif;">
                <span class="material-symbols-rounded text-[18px]">star</span>
                เขียนรีวิว
              </router-link>

              <router-link
                v-if="b.status === 'confirmed'"
                :to="{ name: 'trip-chat', params: { scheduleId: b.schedule.id }, query: { title: b.schedule?.trip?.title, date: b.schedule?.departure_date } }"
                class="flex-1 text-center border-2 border-[#006565] text-[#006565] py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-[#E3F2F2] transition-all flex items-center justify-center gap-1.5"
                style="font-family:'Anuphan',sans-serif;">
                <span class="material-symbols-rounded text-[18px]">chat</span>
                แชท
              </router-link>

              <button
                v-if="canReviewStaff(b)"
                @click="openStaffReviewModal(b)"
                class="flex-1 text-center border-2 border-[#0C4A6E] text-[#0C4A6E] py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-[#E0F2FE] transition-all flex items-center justify-center gap-1.5"
                style="font-family:'Anuphan',sans-serif;">
                <span class="material-symbols-rounded text-[18px]">badge</span>
                รีวิวสตาฟ
              </button>
              
              <button
                v-if="b.status === 'pending'"
                @click="handleCancel(b)"
                class="flex-1 sm:flex-none border border-[#FCA5A5] text-[#DC2626] py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-[#FEF2F2] hover:border-[#F87171] transition-all"
                style="font-family:'Anuphan',sans-serif;">
                ยกเลิก
              </button>

              <button
                v-if="isOngoingTrip(b)"
                @click="openSosModal(b)"
                class="flex-1 sm:flex-none bg-red-600 text-white py-2.5 px-4 rounded-[12px] font-bold text-sm hover:bg-red-700 active:scale-95 transition-all flex items-center justify-center gap-1.5 shadow-sm shadow-red-600/20"
                style="font-family:'Anuphan',sans-serif;">
                <span class="material-symbols-rounded text-[18px]" style="font-variation-settings:'FILL' 1">sos</span>
                SOS
              </button>
            </div>
          </div>
        </article>

        <!-- Pagination -->
        <div v-if="bookingStore.meta && bookingStore.meta.last_page > 1" class="flex justify-center mt-8 gap-2">
          <button
            v-for="page in bookingStore.meta.last_page"
            :key="page"
            @click="bookingStore.fetchMyBookings(page)"
            class="w-9 h-9 rounded-[10px] text-sm font-bold transition-all duration-300"
            :class="page === bookingStore.meta.current_page
              ? 'bg-[#006565] text-white shadow-sm'
              : 'bg-white border border-[#E8EEEF] text-[#505E5E] hover:bg-[#F9FAFA]'"
            style="font-family: 'Anuphan', sans-serif;">
            {{ page }}
          </button>
        </div>
      </div>

      <!-- Staff Review Modal -->
      <div v-if="showStaffReviewModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 px-4" @click.self="showStaffReviewModal = false">
        <div class="bg-white w-full max-w-lg rounded-2xl shadow-xl border border-[#E8EEEF] overflow-hidden">
          <div class="px-5 py-4 border-b border-[#E8EEEF] flex items-center justify-between">
            <div>
              <h3 class="text-lg font-bold text-[#1a1c1c]" style="font-family:'Anuphan',sans-serif;">รีวิวสตาฟประจำทริป</h3>
              <p class="text-xs text-[#6b7280] mt-0.5" style="font-family:'Anuphan',sans-serif;">{{ reviewingBooking?.schedule?.trip?.title || '-' }}</p>
            </div>
            <button @click="showStaffReviewModal = false" class="w-8 h-8 rounded-lg border border-[#E5E7EB] hover:bg-[#F3F4F6] inline-flex items-center justify-center">
              <span class="material-symbols-rounded text-[18px]">close</span>
            </button>
          </div>

          <div class="p-5 space-y-4">
            <div>
              <label class="block text-sm font-semibold text-[#334155] mb-1" style="font-family:'Anuphan',sans-serif;">เลือกสตาฟ</label>
              <select v-model.number="staffReviewForm.staff_user_id" @change="hydrateStaffReviewForm" class="w-full rounded-xl border border-[#D7E0E1] px-3 py-2.5 text-sm" style="font-family:'Anuphan',sans-serif;">
                <option :value="0" disabled>-- เลือกสตาฟ --</option>
                <option v-for="staff in reviewingBooking?.assigned_staff || []" :key="staff.id" :value="staff.id">
                  {{ staff.name }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-semibold text-[#334155] mb-1" style="font-family:'Anuphan',sans-serif;">คะแนนความพึงพอใจ</label>
              <div class="grid grid-cols-5 gap-2">
                <button
                  v-for="score in [1,2,3,4,5]"
                  :key="score"
                  type="button"
                  @click="staffReviewForm.rating = score"
                  class="h-10 rounded-xl border text-sm font-bold transition"
                  :class="staffReviewForm.rating === score ? 'bg-[#006565] text-white border-[#006565]' : 'bg-white text-[#475569] border-[#D7E0E1] hover:bg-[#F8FAFC]'"
                  style="font-family:'Anuphan',sans-serif;"
                >
                  {{ score }} ★
                </button>
              </div>
            </div>

            <div>
              <label class="block text-sm font-semibold text-[#334155] mb-1" style="font-family:'Anuphan',sans-serif;">ความคิดเห็น (ไม่บังคับ)</label>
              <textarea
                v-model="staffReviewForm.comment"
                rows="4"
                class="w-full rounded-xl border border-[#D7E0E1] px-3 py-2.5 text-sm"
                placeholder="เล่าประสบการณ์การดูแลของสตาฟคนนี้"
                style="font-family:'Anuphan',sans-serif;"
              ></textarea>
            </div>

            <div v-if="selectedStaffReview" class="rounded-xl bg-[#F8FAFC] border border-[#E2E8F0] p-3 text-xs text-[#475569]" style="font-family:'Anuphan',sans-serif;">
              คุณเคยรีวิวสตาฟคนนี้แล้ว ระบบจะอัปเดตรีวิวเดิม
            </div>
          </div>

          <div class="px-5 py-4 border-t border-[#E8EEEF] flex justify-end gap-2">
            <button @click="showStaffReviewModal = false" class="px-4 py-2.5 rounded-xl border border-[#D7E0E1] text-sm font-semibold text-[#475569] hover:bg-[#F8FAFC]" style="font-family:'Anuphan',sans-serif;">ยกเลิก</button>
            <button
              @click="submitStaffReview"
              :disabled="reviewSubmitting || !staffReviewForm.staff_user_id"
              class="px-4 py-2.5 rounded-xl bg-[#006565] text-white text-sm font-bold hover:bg-[#004f4f] disabled:opacity-60"
              style="font-family:'Anuphan',sans-serif;"
            >
              {{ selectedStaffReview ? 'อัปเดตรีวิวสตาฟ' : 'ส่งรีวิวสตาฟ' }}
            </button>
          </div>
        </div>
      </div>

    </div>

    <!-- SOS Modal -->
    <Teleport to="body">
      <div v-if="showSosModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-end sm:items-center justify-center z-50 px-4 pb-4 sm:pb-0" @click.self="showSosModal = false">
        <div class="bg-white w-full max-w-md rounded-[24px] shadow-2xl overflow-hidden">
          <!-- Header -->
          <div class="bg-red-600 px-5 py-4 flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center">
              <span class="material-symbols-rounded text-white text-[22px]" style="font-variation-settings:'FILL' 1">sos</span>
            </div>
            <div class="flex-1">
              <h3 class="text-white font-black text-base" style="font-family:'Anuphan',sans-serif;">ขอความช่วยเหลือ SOS</h3>
              <p class="text-red-100 text-xs" style="font-family:'Anuphan',sans-serif;">{{ sosBooking?.schedule?.trip?.title }}</p>
            </div>
            <button @click="showSosModal = false" class="w-8 h-8 rounded-full bg-white/10 hover:bg-white/20 flex items-center justify-center transition-all">
              <span class="material-symbols-rounded text-white text-[18px]">close</span>
            </button>
          </div>

          <div class="p-5 space-y-4">
            <p class="text-sm text-[#505E5E]" style="font-family:'Anuphan',sans-serif;">เลือกข้อความที่ต้องการส่งให้สตาฟและผู้ร่วมทริป</p>

            <!-- Predefined options -->
            <div class="grid grid-cols-2 gap-2">
              <button
                v-for="opt in sosOptions"
                :key="opt.value"
                @click="selectSosOption(opt)"
                class="py-3 px-4 rounded-[14px] text-sm font-bold border-2 transition-all active:scale-95 text-left"
                :class="sosSelectedOption === opt.value
                  ? 'bg-red-50 border-red-500 text-red-700'
                  : 'bg-[#F4F7F6] border-[#E8EEEF] text-[#1a1c1c] hover:border-red-300 hover:bg-red-50/50'"
                style="font-family:'Anuphan',sans-serif;">
                <span class="block text-lg mb-0.5">{{ opt.emoji }}</span>
                {{ opt.label }}
              </button>
            </div>

            <!-- Custom message (shown when "อื่น ๆ" selected) -->
            <div v-if="sosSelectedOption === 'other'" class="space-y-1.5">
              <label class="text-xs font-bold text-[#505E5E] uppercase tracking-wide" style="font-family:'Anuphan',sans-serif;">ระบุเพิ่มเติม</label>
              <textarea
                v-model="sosCustomMessage"
                rows="3"
                maxlength="255"
                placeholder="อธิบายสถานการณ์โดยย่อ..."
                class="w-full rounded-[14px] border-2 border-[#E8EEEF] focus:border-red-400 focus:outline-none px-3.5 py-2.5 text-sm resize-none"
                style="font-family:'Anuphan',sans-serif;"
              ></textarea>
            </div>

            <p class="text-xs text-[#889696] bg-[#F4F7F6] p-3 rounded-[12px]" style="font-family:'Anuphan',sans-serif;">
              <span class="material-symbols-rounded text-[14px] align-middle mr-1" style="font-variation-settings:'FILL' 1">info</span>
              สตาฟและผู้โดยสารในทริปจะได้รับการแจ้งเตือนทันที
            </p>
          </div>

          <div class="px-5 pb-5 flex gap-2">
            <button @click="showSosModal = false" class="flex-1 py-3 rounded-[14px] border-2 border-[#E8EEEF] text-sm font-bold text-[#505E5E] hover:bg-[#F4F7F6] transition-all" style="font-family:'Anuphan',sans-serif;">ยกเลิก</button>
            <button
              @click="submitSos"
              :disabled="!sosSelectedOption || sosSubmitting || (sosSelectedOption === 'other' && !sosCustomMessage.trim())"
              class="flex-1 py-3 rounded-[14px] bg-red-600 text-white font-black text-sm hover:bg-red-700 active:scale-95 transition-all disabled:opacity-50 flex items-center justify-center gap-2 shadow-lg shadow-red-600/20"
              style="font-family:'Anuphan',sans-serif;">
              <span v-if="sosSubmitting" class="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin"></span>
              <span class="material-symbols-rounded text-[18px]" v-else style="font-variation-settings:'FILL' 1">sos</span>
              {{ sosSubmitting ? 'กำลังส่ง...' : 'ส่งสัญญาณ SOS' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useBookingStore } from '../stores/booking';
import { useSeatsStore } from '../stores/seats';
import api from '../lib/axios';
import CountdownTimer from '../components/CountdownTimer.vue';

const router = useRouter();

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
  { value: 'help', label: 'ช่วยด้วย', emoji: '🆘' },
  { value: 'lost', label: 'ฉันหลงทาง', emoji: '🗺️' },
  { value: 'worried', label: 'ฉันกังวล', emoji: '😟' },
  { value: 'unsafe', label: 'ฉันรู้สึกไม่ปลอดภัย', emoji: '⚠️' },
  { value: 'other', label: 'อื่น ๆ', emoji: '💬' },
];

const sosMessageMap = {
  help: 'ช่วยด้วย',
  lost: 'ฉันหลงทาง',
  worried: 'ฉันกังวล',
  unsafe: 'ฉันรู้สึกไม่ปลอดภัย',
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
    && seatsStore.activeBookingInfo?.scheduleId == b.schedule?.id
    && seatsStore.countdownSeconds > 0;
}

const upcomingStatuses = ['pending', 'confirmed'];
const pastStatuses = ['cancelled', 'refunded', 'completed'];

const filteredBookings = computed(() => {
  return bookingStore.bookings.filter(b =>
    activeTab.value === 'upcoming'
      ? upcomingStatuses.includes(b.status)
      : pastStatuses.includes(b.status)
  );
});

const statusMap = {
  pending:   'รอชำระเงิน',
  confirmed: 'ยืนยันแล้ว',
  cancelled: 'ยกเลิกแล้ว',
  refunded:  'คืนเงินแล้ว',
  completed: 'เสร็จสิ้นแล้ว',
};

function statusLabel(s) { return statusMap[s] || s; }

function statusClass(s) {
  const map = {
    pending:   'bg-[#FFFAF0] text-[#D97706] border border-[#FDE68A]',
    confirmed: 'bg-[#F0FAFA] text-[#006565] border border-[#BCDFDF]',
    cancelled: 'bg-[#F4F7F6] text-[#505E5E] border border-[#E8EEEF]',
    refunded:  'bg-[#F4F7F6] text-[#505E5E] border border-[#E8EEEF]',
    completed: 'bg-[#EFF6FF] text-[#2563EB] border border-[#BFDBFE]',
  };
  return map[s] || 'bg-[#F4F7F6] text-[#505E5E] border border-[#E8EEEF]';
}

function statusDotClass(s) {
  const map = {
    pending:   'bg-[#D97706]',
    confirmed: 'bg-[#006565]',
    cancelled: 'bg-[#A0B0B0]',
    refunded:  'bg-[#A0B0B0]',
    completed: 'bg-[#2563EB]',
  };
  return map[s] || 'bg-[#A0B0B0]';
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' });
}

function formatShortDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'short' });
}

// ── Installment helper functions ──
function getPaidInstallments(b) {
  return (b.installment_payments || []).filter(ip => ip.status === 'paid');
}

function getNextPendingInstallment(b) {
  return (b.installment_payments || []).find(ip => ip.status !== 'paid');
}

function isNextDue(b, inst) {
  const next = getNextPendingInstallment(b);
  return next && next.installment_no === inst.installment_no;
}

function isOverdue(inst) {
  if (!inst?.due_date) return false;
  const due = new Date(inst.due_date);
  due.setHours(23, 59, 59);
  return new Date() > due;
}

function isDueSoon(inst) {
  if (!inst?.due_date) return false;
  const due = new Date(inst.due_date);
  const now = new Date();
  const diffDays = Math.ceil((due - now) / (1000 * 60 * 60 * 24));
  return diffDays <= 7 && diffDays >= 0;
}

function getDaysUntil(dateStr) {
  if (!dateStr) return '';
  const due = new Date(dateStr);
  const now = new Date();
  const diffMs = due - now;
  const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24));
  if (diffDays < 0) return 'เลยกำหนด';
  if (diffDays === 0) return 'วันนี้!';
  if (diffDays === 1) return 'พรุ่งนี้';
  return `อีก ${diffDays} วัน`;
}

function goToInstallmentPayment(b) {
  router.push(`/installment-payment/${b.booking_ref}`);
}

function getDay(d) {
  if (!d) return '';
  return new Date(d).getDate();
}

function getMonthShort(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { month: 'short' });
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
    await bookingStore.fetchMyBookings(bookingStore.meta?.current_page || 1);
    alert('บันทึกรีวิวสตาฟเรียบร้อยแล้ว');
  } catch (e) {
    alert(e?.response?.data?.message || 'ส่งรีวิวสตาฟไม่สำเร็จ');
  } finally {
    reviewSubmitting.value = false;
  }
}

function isOngoingTrip(b) {
  if (b.status !== 'confirmed') return false;
  const departure = b.schedule?.departure_date;
  const returnDate = b.schedule?.return_date;
  if (!departure) return false;
  const today = new Date().toISOString().split('T')[0];
  const end = returnDate || departure;
  return today >= departure && today <= end;
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
    alert('ส่งสัญญาณ SOS เรียบร้อยแล้ว สตาฟและผู้ร่วมทริปได้รับแจ้งเตือนแล้ว');
  } catch (e) {
    alert(e?.response?.data?.message || 'ส่ง SOS ไม่สำเร็จ กรุณาลองใหม่');
  } finally {
    sosSubmitting.value = false;
  }
}

async function handleCancel(b) {
  if (!confirm('ต้องการยกเลิกการจองนี้หรือไม่?')) return;
  try {
    await bookingStore.cancelBooking(b.booking_ref, 'ยกเลิกโดยลูกค้า');
    await bookingStore.fetchMyBookings();
  } catch (e) {
    alert(e?.response?.data?.message || 'ยกเลิกไม่สำเร็จ');
  }
}

onMounted(() => {
  bookingStore.fetchMyBookings();
});
</script>
