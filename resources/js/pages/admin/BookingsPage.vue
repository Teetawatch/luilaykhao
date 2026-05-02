<template>
  <div class="space-y-6 pb-10">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h1 class="font-anuphan text-2xl font-bold text-text-dark flex items-center gap-3">
          <div class="w-10 h-10 bg-accent/10 rounded-xl flex items-center justify-center text-accent">
            <span class="material-symbols-rounded">confirmation_number</span>
          </div>
          จัดการการจอง
        </h1>
        <p class="text-sm text-text-muted mt-1 ml-[52px]">ดูและจัดการการจองทั้งหมดในระบบ</p>
      </div>
      <button @click="openManualBookingModal" class="inline-flex items-center gap-2 px-5 py-2.5 bg-accent text-white rounded-2xl font-bold shadow-lg shadow-accent/20 hover:bg-accent-mid transition-all">
        <span class="material-symbols-rounded">add_circle</span>
        เพิ่มการจองด้วยตนเอง
      </button>
    </div>

    <!-- Filters -->
    <div class="bg-white p-4 rounded-2xl shadow-sm border border-sand-dark/50 flex flex-col sm:flex-row gap-4">
      <div class="relative flex-1">
        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
          <span class="material-symbols-rounded text-text-muted/60 text-[20px]">search</span>
        </div>
        <input v-model="filters.search" placeholder="ค้นหารหัสจอง, ชื่อ, อีเมล..." @input="debouncedFetch"
          class="w-full bg-sand/30 border border-sand-dark/60 rounded-xl pl-11 pr-4 py-2.5 text-sm transition-all focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none placeholder:text-text-muted/50" />
      </div>
      <div class="flex gap-4 w-full sm:w-auto">
        <select v-model="filters.status" @change="fetchData()"
          class="flex-1 sm:flex-none bg-sand/30 border border-sand-dark/60 rounded-xl px-4 py-2.5 text-sm transition-all focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none min-w-[140px]">
          <option value="">ทุกสถานะ</option>
          <option value="pending">รอดำเนินการ</option>
          <option value="confirmed">ยืนยันแล้ว</option>
          <option value="cancelled">ยกเลิก</option>
          <option value="refunded">คืนเงินแล้ว</option>
        </select>
        <input type="date" v-model="filters.date" @change="fetchData()"
          class="flex-1 sm:flex-none bg-sand/30 border border-sand-dark/60 rounded-xl px-4 py-2.5 text-sm transition-all focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none" />
        <select v-model="filters.booking_type" @change="fetchData()"
          class="flex-1 sm:flex-none bg-sand/30 border border-sand-dark/60 rounded-xl px-4 py-2.5 text-sm transition-all focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none min-w-[140px]">
          <option value="">ทุกประเภท</option>
          <option value="join_trip">จอยทริป</option>
          <option value="regular">จองปกติ</option>
        </select>
      </div>
    </div>

    <!-- Table Card -->
    <div class="bg-white rounded-2xl shadow-sm border border-sand-dark/50 overflow-hidden">
      <div v-if="admin.loading" class="flex justify-center p-12">
        <div class="w-8 h-8 border-4 border-sand-dark border-t-accent rounded-full animate-spin"></div>
      </div>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-left border-collapse min-w-[800px]">
          <thead>
            <tr class="bg-sand/30 border-b border-sand-dark/50 text-xs uppercase text-text-muted font-semibold tracking-wide">
              <th class="px-6 py-4">รหัสจอง</th>
              <th class="px-6 py-4">ผู้จอง</th>
              <th class="px-6 py-4">ทริป</th>
              <th class="px-6 py-4 text-right">จำนวนเงิน</th>
              <th class="px-6 py-4 text-center">สถานะ</th>
              <th class="px-6 py-4">วันที่จอง</th>
              <th class="px-6 py-4 text-center">การจัดการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-sand-dark/30">
            <tr v-for="b in admin.bookings.data" :key="b.id" class="hover:bg-sand/10 transition-colors">
              <td class="px-6 py-4">
                <span class="font-anuphan font-bold text-accent">{{ b.booking_ref }}</span>
              </td>
              <td class="px-6 py-4">
                <div v-if="b.user">
                  <span class="block font-semibold text-text-dark text-sm">{{ b.user?.name || '-' }}</span>
                  <span class="block text-xs text-text-muted mt-0.5">{{ b.user?.email || '' }}</span>
                </div>
              </td>
              <td class="px-6 py-4 text-sm text-text-dark">
                <div class="flex items-center gap-2">
                  <span>{{ b.schedule?.trip?.title || '-' }}</span>
                  <span v-if="b.is_join_trip" class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-600 border border-emerald-200 whitespace-nowrap">
                    <span class="material-symbols-rounded text-[12px]">group_add</span>
                    จอยทริป
                  </span>
                </div>
              </td>
              <td class="px-6 py-4 text-right">
                <div class="flex flex-col items-end">
                  <span class="text-sm font-semibold text-text-dark">{{ formatMoney(b.total_amount) }}</span>
                  <div class="mt-1 flex flex-wrap justify-end gap-1">
                    <span v-if="b.payment_type === 'installment'" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-blue-50 text-blue-600 border border-blue-100 uppercase tracking-tighter">
                      <span class="material-symbols-rounded text-[12px] mr-0.5">payments</span>
                      ผ่อนชำระ
                    </span>
                    <span v-else class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-green-50 text-green-600 border border-green-100 uppercase tracking-tighter">
                      <span class="material-symbols-rounded text-[12px] mr-0.5">check_circle</span>
                      จ่ายเต็ม
                    </span>
                    
                    <span v-if="b.payment_type === 'installment' && b.installment_payments" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold"
                      :class="getRemainingInstallments(b) === 0 ? 'bg-green-50 text-green-700 border border-green-100' : 'bg-orange-50 text-orange-700 border border-orange-100'">
                      เหลือ {{ getRemainingInstallments(b) }} / {{ b.installment_count }} งวด
                    </span>
                  </div>
                </div>
              </td>
              <td class="px-6 py-4 text-center">
                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold" :class="statusClass(b.status)">
                  {{ statusLabels[b.status] }}
                </span>
              </td>
              <td class="px-6 py-4 text-sm text-text-muted">
                {{ formatDate(b.created_at) }}
              </td>
              <td class="px-6 py-4">
                <div class="flex items-center justify-center gap-2">
                  <button @click="openDetail(b)" class="w-8 h-8 rounded-lg bg-sand/50 text-accent hover:bg-accent/10 border border-transparent hover:border-accent/20 flex items-center justify-center transition-all" title="รายละเอียด">
                    <span class="material-symbols-rounded text-[18px]">visibility</span>
                  </button>
                  <button @click="openStatusModal(b)" class="w-8 h-8 rounded-lg bg-sand/50 border border-transparent flex items-center justify-center transition-all"
                    :class="b.status === 'pending' ? 'text-accent hover:bg-accent/10 hover:border-accent/20' : 'text-red-500 hover:bg-red-50 hover:border-red-200'" title="เปลี่ยนสถานะ">
                    <span class="material-symbols-rounded text-[18px]">swap_horiz</span>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!admin.bookings.data?.length">
              <td colspan="7" class="px-6 py-12 text-center text-text-muted text-sm">
                <span class="material-symbols-rounded text-4xl mb-3 text-sand-dark block">inbox</span>
                ไม่พบข้อมูลการจอง
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="admin.bookings.meta?.last_page > 1" class="flex items-center justify-center gap-4 px-6 py-4 border-t border-sand-dark/50 bg-sand/10">
        <button :disabled="admin.bookings.meta.current_page <= 1" @click="goPage(admin.bookings.meta.current_page - 1)"
          class="w-9 h-9 rounded-xl border border-sand-dark/60 bg-white flex items-center justify-center text-text-muted hover:bg-sand hover:text-accent hover:border-accent/30 disabled:opacity-50 disabled:cursor-not-allowed transition-all">
          <span class="material-symbols-rounded text-[20px]">chevron_left</span>
        </button>
        <span class="text-sm font-medium text-text-muted">
          {{ admin.bookings.meta.current_page }} / {{ admin.bookings.meta.last_page }}
        </span>
        <button :disabled="admin.bookings.meta.current_page >= admin.bookings.meta.last_page" @click="goPage(admin.bookings.meta.current_page + 1)"
          class="w-9 h-9 rounded-xl border border-sand-dark/60 bg-white flex items-center justify-center text-text-muted hover:bg-sand hover:text-accent hover:border-accent/30 disabled:opacity-50 disabled:cursor-not-allowed transition-all">
          <span class="material-symbols-rounded text-[20px]">chevron_right</span>
        </button>
      </div>
    </div>

    <!-- Detail Modal -->
    <div v-if="showDetail" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm transition-opacity">
      <div class="bg-white rounded-3xl w-full max-w-3xl max-h-[90vh] overflow-hidden shadow-2xl flex flex-col border border-sand-dark/20 animate-in fade-in zoom-in-95 duration-200">
        <div class="px-6 py-5 border-b border-sand-dark/50 flex items-center justify-between bg-sand/10">
          <h2 class="font-anuphan text-xl font-bold text-text-dark flex items-center gap-2">
            <span class="material-symbols-rounded text-accent">receipt_long</span> รายละเอียดการจอง
          </h2>
          <button @click="showDetail = false" class="w-8 h-8 rounded-full bg-white border border-sand-dark/50 flex items-center justify-center text-text-muted hover:text-red-500 hover:bg-red-50 hover:border-red-200 transition-all">
            <span class="material-symbols-rounded text-[20px]">close</span>
          </button>
        </div>
        
        <div class="p-6 overflow-y-auto custom-scrollbar" v-if="detailBooking">
          <!-- Section: ข้อมูลการจอง -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div class="space-y-1">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider">รหัสจอง</label>
              <div class="font-anuphan font-bold text-accent text-lg">{{ detailBooking.booking_ref }}</div>
            </div>
            <div class="space-y-1">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider">สถานะ</label>
              <div>
                <span class="inline-flex px-3 py-1 rounded-full text-xs font-semibold" :class="statusClass(detailBooking.status)">
                  {{ statusLabels[detailBooking.status] }}
                </span>
                <span v-if="detailBooking.is_join_trip" class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-600 border border-emerald-200 mt-1">
                  <span class="material-symbols-rounded text-[14px]">group_add</span>
                  จอยทริป (Enjoy Trip)
                </span>
              </div>
            </div>
            <div class="space-y-1">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider">ทริป</label>
              <div class="text-sm font-medium text-text-dark">{{ detailBooking.schedule?.trip?.title || '-' }}</div>
            </div>
            <div class="space-y-1">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider">วันเดินทาง</label>
              <div class="text-sm font-medium text-text-dark">{{ detailBooking.schedule?.departure_date || '-' }}</div>
            </div>
            <div class="space-y-1">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider">วันที่จอง</label>
              <div class="text-sm font-medium text-text-dark">{{ formatDateTime(detailBooking.created_at) }}</div>
            </div>
            <div class="space-y-1">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider">เช็คอิน</label>
              <div v-if="detailBooking.checked_in" class="text-sm font-medium text-green-600 flex items-center gap-1.5">
                <span class="material-symbols-rounded text-[18px]">check_circle</span> {{ formatDate(detailBooking.checked_in_at) }}
              </div>
              <div v-else class="text-sm font-medium text-text-muted italic">ยังไม่เช็คอิน</div>
            </div>
          </div>

          <!-- Section: ผู้จอง -->
          <div v-if="detailBooking.user" class="mb-6 p-4 bg-sand/20 border border-sand-dark/40 rounded-2xl">
            <h3 class="text-xs font-bold text-text-muted uppercase tracking-wider mb-3 flex items-center gap-1.5">
              <span class="material-symbols-rounded text-[16px] text-accent">person</span> ข้อมูลผู้จอง
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
              <div>
                <span class="text-xs text-text-muted block">ชื่อ</span>
                <span class="font-semibold text-text-dark">{{ detailBooking.user.name }}</span>
              </div>
              <div>
                <span class="text-xs text-text-muted block">อีเมล</span>
                <span class="font-medium text-text-dark">{{ detailBooking.user.email }}</span>
              </div>
              <div v-if="detailBooking.user.phone">
                <span class="text-xs text-text-muted block">เบอร์โทร</span>
                <span class="font-medium text-text-dark">{{ detailBooking.user.phone }}</span>
              </div>
              <div v-if="detailBooking.passengers?.some(p => p.halal_food)" class="col-span-1 md:col-span-2">
                <span class="text-xs text-text-muted block mb-1">ความต้องการพิเศษ</span>
                <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-green-50 text-green-700 border border-green-200 rounded-full text-xs font-bold">
                  <span class="material-symbols-rounded text-[16px]">eco</span> ต้องการอาหารฮาลาล
                </span>
              </div>
            </div>
          </div>

          <!-- Section: จุดขึ้นรถ -->
          <div v-if="detailBooking.pickup_region" class="mb-6 p-4 bg-blue-50/60 border border-blue-100 rounded-2xl">
            <h3 class="text-xs font-bold text-blue-500 uppercase tracking-wider mb-3 flex items-center gap-1.5">
              <span class="material-symbols-rounded text-[16px]">directions_bus</span> จุดขึ้นรถ
            </h3>
            <div class="text-sm font-medium text-text-dark flex flex-col gap-1">
              <!-- Selected Point (New Bookings) -->
              <div v-if="detailBooking.pickup_point" class="flex flex-col gap-0.5">
                <span class="inline-flex items-center gap-1.5 font-bold text-accent">
                  <span class="material-symbols-rounded text-[16px]">location_on</span>
                  {{ detailBooking.pickup_point.region_label }}
                </span>
                <span class="text-xs text-text-muted">
                  {{ detailBooking.pickup_point.pickup_location }}
                  <span v-if="detailBooking.pickup_point.notes"> · {{ detailBooking.pickup_point.notes }}</span>
                </span>
                <a v-if="detailBooking.pickup_point.map_url" :href="detailBooking.pickup_point.map_url" target="_blank" class="text-xs text-blue-500 hover:underline flex items-center gap-1 mt-0.5">
                  <span class="material-symbols-rounded text-[14px]">map</span> ดูแผนที่
                </a>
              </div>
              
              <!-- Fallback for Old Bookings -->
              <template v-else-if="detailBooking.pickup_region">
                <template v-for="pt in (detailBooking.schedule?.pickup_points || [])" :key="pt.id">
                  <div v-if="pt.region === detailBooking.pickup_region" class="flex flex-col gap-0.5">
                    <span class="inline-flex items-center gap-1.5 font-bold text-accent">
                      <span class="material-symbols-rounded text-[16px]">location_on</span>
                      {{ pt.region_label }}
                    </span>
                    <span class="text-xs text-text-muted">{{ pt.pickup_location }}<span v-if="pt.notes"> · {{ pt.notes }}</span></span>
                    <a v-if="pt.map_url" :href="pt.map_url" target="_blank" class="text-xs text-blue-500 hover:underline flex items-center gap-1 mt-0.5">
                      <span class="material-symbols-rounded text-[14px]">map</span> ดูแผนที่
                    </a>
                  </div>
                </template>
                <span v-if="!(detailBooking.schedule?.pickup_points || []).some(pt => pt.region === detailBooking.pickup_region)" class="text-text-muted">
                  {{ detailBooking.pickup_region }}
                </span>
              </template>
            </div>
          </div>

          <!-- Section: การชำระเงิน -->
          <div class="mb-6 p-4 bg-green-50/60 border border-green-100 rounded-2xl">
            <h3 class="text-xs font-bold text-green-600 uppercase tracking-wider mb-3 flex items-center gap-1.5">
              <span class="material-symbols-rounded text-[16px]">payments</span> การชำระเงิน
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
              <div class="space-y-1">
                <label class="text-xs font-bold text-text-muted uppercase tracking-wider">จำนวนเงินรวม</label>
                <div class="font-bold text-text-dark">{{ formatMoney(detailBooking.total_amount) }}</div>
              </div>
              <div class="space-y-1">
                <label class="text-xs font-bold text-text-muted uppercase tracking-wider">ชำระแล้ว</label>
                <div class="font-semibold text-green-600">{{ formatMoney(detailBooking.paid_amount) }}</div>
              </div>
              <div class="space-y-1">
                <label class="text-xs font-bold text-text-muted uppercase tracking-wider">ประเภทการชำระ</label>
                <div>
                  <span v-if="detailBooking.payment_type === 'installment'" class="inline-flex items-center gap-1 text-purple-700 bg-purple-100 border border-purple-200 px-2 py-0.5 rounded-full text-xs font-bold">
                    <span class="material-symbols-rounded text-[13px]">calendar_month</span> ผ่อนชำระ {{ detailBooking.installment_count }} งวด
                  </span>
                  <span v-else class="inline-flex items-center gap-1 text-green-700 bg-green-100 border border-green-200 px-2 py-0.5 rounded-full text-xs font-bold">
                    <span class="material-symbols-rounded text-[13px]">check_circle</span> ชำระเต็มจำนวน
                  </span>
                </div>
              </div>
              <div class="space-y-1">
                <label class="text-xs font-bold text-text-muted uppercase tracking-wider">ช่องทางชำระ</label>
                <div class="font-medium text-text-dark flex items-center gap-1.5">
                  <span v-if="detailBooking.payment_method === 'promptpay'" class="material-symbols-rounded text-[18px] text-purple-500">qr_code</span>
                  <span v-else-if="detailBooking.payment_method === 'mobile_banking'" class="material-symbols-rounded text-[18px] text-blue-500">smartphone</span>
                  <span v-else-if="detailBooking.payment_method" class="material-symbols-rounded text-[18px] text-text-muted">credit_card</span>
                  {{ paymentMethodLabel(detailBooking.payment_method) }}
                </div>
              </div>
              <div v-if="detailBooking.paid_at" class="space-y-1">
                <label class="text-xs font-bold text-text-muted uppercase tracking-wider">ชำระเมื่อ</label>
                <div class="font-medium text-text-dark">{{ formatDate(detailBooking.paid_at) }}</div>
              </div>
              <div v-if="detailBooking.transfer_datetime" class="space-y-1">
                <label class="text-xs font-bold text-text-muted uppercase tracking-wider">วันเวลาโอนเงิน</label>
                <div class="font-medium text-text-dark">{{ formatDateTime(detailBooking.transfer_datetime) }}</div>
              </div>
              <div v-if="detailBooking.payment_ref" class="space-y-1 col-span-1 md:col-span-2">
                <label class="text-xs font-bold text-text-muted uppercase tracking-wider">รหัสอ้างอิงการชำระ</label>
                <div class="font-mono text-xs text-text-dark bg-sand/30 px-2 py-1 rounded-lg inline-block">{{ detailBooking.payment_ref }}</div>
              </div>
            </div>

            <!-- Slip image -->
            <div v-if="detailBooking.slip_url" class="mt-4">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider block mb-2">สลิปการโอนเงิน</label>
              <a :href="detailBooking.slip_url" target="_blank" class="inline-block">
                <img :src="detailBooking.slip_url" alt="สลิปโอนเงิน"
                  class="max-h-64 rounded-xl border border-sand-dark/40 shadow-sm hover:shadow-md transition-shadow object-contain bg-white" />
              </a>
            </div>
            <div v-else-if="detailBooking.status === 'confirmed' || detailBooking.status === 'pending'" class="mt-3 text-xs text-text-muted italic flex items-center gap-1">
              <span class="material-symbols-rounded text-[15px]">image_not_supported</span> ไม่มีสลิปแนบ
            </div>
          </div>

          <!-- Section: การผ่อนชำระ -->
          <div v-if="detailBooking.payment_type === 'installment' && detailBooking.installment_payments?.length" class="mb-6">
            <h3 class="text-sm font-bold text-text-dark mb-3 flex items-center gap-2 border-b border-sand-dark/50 pb-2">
              <span class="material-symbols-rounded text-accent">calendar_month</span> งวดการผ่อนชำระ ({{ detailBooking.installment_payments.length }} งวด)
            </h3>
            <div class="space-y-2">
              <div v-for="ip in detailBooking.installment_payments" :key="ip.id"
                class="flex flex-col sm:flex-row sm:items-center gap-2 p-3 rounded-xl border"
                :class="ip.status === 'paid' ? 'bg-green-50/60 border-green-200' : 'bg-sand/20 border-sand-dark/40'">
                <div class="flex items-center gap-2 shrink-0">
                  <span class="w-6 h-6 rounded-full text-xs font-bold flex items-center justify-center"
                    :class="ip.status === 'paid' ? 'bg-green-500 text-white' : 'bg-sand-dark/30 text-text-muted'">
                    {{ ip.installment_no }}
                  </span>
                  <span class="text-sm font-bold" :class="ip.status === 'paid' ? 'text-green-700' : 'text-text-muted'">{{ formatMoney(ip.amount) }}</span>
                </div>
                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-text-muted flex-1">
                  <span>ครบกำหนด: <span class="font-medium text-text-dark">{{ ip.due_date || '-' }}</span></span>
                  <span v-if="ip.paid_at">ชำระเมื่อ: <span class="font-medium text-green-700">{{ formatDate(ip.paid_at) }}</span></span>
                  <span v-if="ip.payment_method">ช่องทาง: <span class="font-medium text-text-dark">{{ paymentMethodLabel(ip.payment_method) }}</span></span>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                  <span class="text-[11px] font-bold px-2 py-0.5 rounded-full"
                    :class="ip.status === 'paid' ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-yellow-100 text-yellow-700 border border-yellow-200'">
                    {{ ip.status === 'paid' ? 'ชำระแล้ว' : 'รอชำระ' }}
                  </span>
                  <a v-if="ip.slip_url" :href="ip.slip_url" target="_blank"
                    class="text-xs text-blue-500 hover:text-blue-700 flex items-center gap-0.5 font-medium">
                    <span class="material-symbols-rounded text-[15px]">receipt</span> สลิป
                  </a>
                </div>
              </div>
            </div>
          </div>

          <!-- Section: หมายเหตุ / อื่นๆ -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div v-if="detailBooking.cancellation_reason" class="space-y-1 col-span-1 md:col-span-2 p-3 bg-red-50 rounded-xl border border-red-100">
              <label class="text-xs font-bold text-red-500 uppercase tracking-wider">เหตุผลที่ยกเลิก</label>
              <div class="text-sm font-medium text-red-700 mt-1">{{ detailBooking.cancellation_reason }}</div>
            </div>
            <div v-if="detailBooking.is_group" class="space-y-1">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider">การจองกลุ่ม</label>
              <div class="text-sm font-medium text-text-dark">{{ detailBooking.group_name || 'ใช่' }}</div>
            </div>
            <div v-if="detailBooking.group_notes" class="space-y-1 col-span-1 md:col-span-2">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider">หมายเหตุกลุ่ม</label>
              <div class="text-sm font-medium text-text-dark bg-sand/30 p-3 rounded-xl">{{ detailBooking.group_notes }}</div>
            </div>
            <div v-if="detailBooking.qr_code" class="space-y-1 col-span-1 md:col-span-2 flex flex-col items-center p-4 bg-sand/20 rounded-2xl border border-sand-dark/30">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider mb-2">QR Code สำหรับเช็คอิน</label>
              <div class="bg-white p-3 rounded-xl shadow-sm border border-sand-dark/20">
                <img :src="`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${detailBooking.qr_code}`" 
                  alt="Booking QR Code" class="w-40 h-40" />
              </div>
              <div class="mt-2 font-mono text-[10px] text-text-muted">{{ detailBooking.qr_code }}</div>
            </div>
          </div>

          <!-- Passengers -->
          <div v-if="detailBooking.passengers?.length" class="mb-8">
            <h3 class="text-sm font-bold text-text-dark mb-4 flex items-center gap-2 border-b border-sand-dark/50 pb-2">
              <span class="material-symbols-rounded text-accent">group</span> ผู้โดยสาร ({{ detailBooking.passengers.length }})
            </h3>
            <div class="grid grid-cols-1 gap-4">
              <div v-for="(p, idx) in detailBooking.passengers" :key="p.id" class="bg-sand/20 border border-sand-dark/50 rounded-2xl p-4 hover:bg-sand/40 transition-colors">
                <!-- Header -->
                <div class="flex items-center gap-2 mb-3 pb-2 border-b border-sand-dark/30">
                  <span class="w-6 h-6 rounded-full bg-accent text-white text-xs font-bold flex items-center justify-center shrink-0">{{ idx + 1 }}</span>
                  <span class="font-bold text-text-dark">{{ p.title }} {{ p.name }}</span>
                  <span v-if="p.nickname" class="text-xs text-text-muted">({{ p.nickname }})</span>
                  <span v-if="p.halal_food === true" class="ml-auto text-[11px] font-bold bg-green-100 text-green-700 border border-green-200 px-2 py-0.5 rounded-full flex items-center gap-1">
                    <span class="material-symbols-rounded text-[14px]">eco</span> ฮาลาล
                  </span>
                  <span v-else-if="p.halal_food === false" class="ml-auto text-[11px] font-bold bg-gray-100 text-gray-500 border border-gray-200 px-2 py-0.5 rounded-full">
                    ไม่ฮาลาล
                  </span>
                </div>
                <!-- Info grid -->
                <div class="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-2 text-xs">
                  <div v-if="p.phone">
                    <span class="text-text-muted block">เบอร์โทร</span>
                    <span class="font-semibold text-text-dark">{{ p.phone }}</span>
                  </div>
                  <div v-if="p.id_card">
                    <span class="text-text-muted block">เลขบัตรประชาชน</span>
                    <span class="font-semibold text-text-dark">{{ p.id_card }}</span>
                  </div>
                  <div v-if="p.blood_group">
                    <span class="text-text-muted block">กรุ๊ปเลือด</span>
                    <span class="font-bold text-red-600">{{ p.blood_group }}</span>
                  </div>
                  <div v-if="p.emergency_contact">
                    <span class="text-text-muted block">ผู้ติดต่อฉุกเฉิน</span>
                    <span class="font-semibold text-text-dark">{{ p.emergency_contact }}</span>
                  </div>
                  <div v-if="p.emergency_phone">
                    <span class="text-text-muted block">เบอร์ฉุกเฉิน</span>
                    <span class="font-semibold text-text-dark">{{ p.emergency_phone }}</span>
                  </div>
                  <div v-if="p.weight">
                    <span class="text-text-muted block">น้ำหนัก</span>
                    <span class="font-semibold text-text-dark">{{ p.weight }} กก.</span>
                  </div>
                  <div v-if="p.dive_cert_level" class="col-span-2 md:col-span-1">
                    <span class="text-text-muted block">ระดับใบดำน้ำ</span>
                    <span class="font-semibold text-text-dark">{{ p.dive_cert_level }}</span>
                    <span v-if="p.cert_number" class="text-text-muted ml-1">({{ p.cert_number }})</span>
                  </div>
                  <div v-if="p.allergies" class="col-span-2 md:col-span-3">
                    <span class="text-text-muted block">การแพ้อาหาร / อื่นๆ</span>
                    <span class="font-semibold text-orange-600">{{ p.allergies }}</span>
                  </div>
                  <div v-if="p.health_notes" class="col-span-2 md:col-span-3">
                    <span class="text-text-muted block">หมายเหตุสุขภาพ</span>
                    <span class="font-semibold text-orange-700">{{ p.health_notes }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Seats -->
          <div v-if="detailBooking.seats?.length">
            <h3 class="text-sm font-bold text-text-dark mb-4 flex items-center gap-2 border-b border-sand-dark/50 pb-2">
              <span class="material-symbols-rounded text-accent text-[20px]">chair</span> ที่นั่ง
            </h3>
            <div class="flex flex-wrap gap-2">
              <span v-for="s in detailBooking.seats" :key="s.id" 
                class="px-3 py-1.5 bg-accent/10 text-accent font-bold text-sm rounded-lg border border-accent/20">
                {{ s.seat_id }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Status Change Modal -->
    <div v-if="showStatusModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm transition-opacity">
      <div class="bg-white rounded-3xl w-full max-w-md overflow-hidden shadow-2xl flex flex-col border border-sand-dark/20 animate-in fade-in zoom-in-95 duration-200">
        <div class="px-6 py-5 border-b border-sand-dark/50 flex items-center justify-between bg-sand/10">
          <h2 class="font-anuphan text-xl font-bold text-text-dark flex items-center gap-2">
            <span class="material-symbols-rounded text-accent">swap_horiz</span> เปลี่ยนสถานะ
          </h2>
          <button @click="showStatusModal = false" class="w-8 h-8 rounded-full bg-white border border-sand-dark/50 flex items-center justify-center text-text-muted hover:text-red-500 hover:bg-red-50 hover:border-red-200 transition-all">
            <span class="material-symbols-rounded text-[20px]">close</span>
          </button>
        </div>
        <div class="p-6 space-y-4">
          <div class="space-y-1.5">
            <label class="text-sm font-medium text-text-dark">สถานะใหม่</label>
            <select v-model="statusForm.status"
              class="w-full bg-sand/30 border border-sand-dark/60 rounded-xl px-4 py-2.5 text-sm transition-all focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none">
              <option value="pending">รอดำเนินการ</option>
              <option value="confirmed">ยืนยันแล้ว</option>
              <option value="cancelled">ยกเลิก</option>
              <option value="refunded">คืนเงินแล้ว</option>
            </select>
          </div>
          <div v-if="statusForm.status === 'cancelled' || statusForm.status === 'refunded'" class="space-y-1.5">
            <label class="text-sm font-medium text-text-dark">เหตุผล</label>
            <textarea v-model="statusForm.reason" rows="3" placeholder="ระบุเหตุผลที่เปลี่ยนสถานะ..."
              class="w-full bg-sand/30 border border-sand-dark/60 rounded-xl px-4 py-3 text-sm transition-all focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none resize-none"></textarea>
          </div>
        </div>
        <div class="px-6 py-4 border-t border-sand-dark/50 bg-sand/10 flex justify-end gap-3">
          <button @click="showStatusModal = false" 
            class="px-5 py-2.5 rounded-xl border border-sand-dark/60 bg-white text-text-dark font-medium hover:bg-sand transition-all text-sm">
            ยกเลิก
          </button>
          <button @click="doUpdateStatus" :disabled="submitting"
            class="px-5 py-2.5 rounded-xl bg-accent text-white font-bold hover:bg-accent-mid shadow-lg shadow-accent/20 hover:shadow-xl transition-all disabled:opacity-70 flex items-center gap-2 text-sm">
            <span v-if="submitting" class="material-symbols-rounded animate-spin text-[20px]">sync</span>
            <span v-else class="material-symbols-rounded text-[20px]">save</span>
            บันทึกการเปลี่ยนแปลง
          </button>
        </div>
      </div>
    </div>
    </div>

    <!-- Manual Booking Modal -->
    <div v-if="showManualModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm transition-opacity">
      <div class="bg-white rounded-3xl w-full max-w-2xl max-h-[90vh] overflow-hidden shadow-2xl flex flex-col border border-sand-dark/20 animate-in fade-in zoom-in-95 duration-200">
        <div class="px-6 py-5 border-b border-sand-dark/50 flex items-center justify-between bg-sand/10">
          <h2 class="font-anuphan text-xl font-bold text-text-dark flex items-center gap-2">
            <span class="material-symbols-rounded text-accent">add_circle</span> เพิ่มการจองด้วยตนเอง
          </h2>
          <button @click="showManualModal = false" class="w-8 h-8 rounded-full bg-white border border-sand-dark/50 flex items-center justify-center text-text-muted hover:text-red-500 hover:bg-red-50 hover:border-red-200 transition-all">
            <span class="material-symbols-rounded text-[20px]">close</span>
          </button>
        </div>
        
        <div class="p-6 overflow-y-auto custom-scrollbar">
          <form @submit.prevent="doCreateManualBooking" class="space-y-6">
            <!-- Trip & Schedule Selection -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="space-y-1.5">
                <label class="text-sm font-bold text-text-dark">ทริป</label>
                <select v-model="manualForm.trip_id" @change="onTripChange" required
                  class="w-full bg-sand/30 border border-sand-dark/60 rounded-xl px-4 py-2.5 text-sm transition-all focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none">
                  <option value="">เลือกทริป</option>
                  <option v-for="trip in allTrips" :key="trip.id" :value="trip.id">{{ trip.title }}</option>
                </select>
              </div>
              <div class="space-y-1.5">
                <label class="text-sm font-bold text-text-dark">วันที่เดินทาง</label>
                <select v-model="manualForm.schedule_id" :disabled="!manualForm.trip_id" required
                  class="w-full bg-sand/30 border border-sand-dark/60 rounded-xl px-4 py-2.5 text-sm transition-all focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none disabled:opacity-50">
                  <option value="">เลือกรอบเดินทาง</option>
                  <option v-for="s in availableSchedules" :key="s.id" :value="s.id">
                    {{ formatDate(s.departure_date) }} 
                    <span v-if="s.join_trip_enabled">(จอยทริป)</span>
                    <span v-else>({{ s.available_seats }} ที่ว่าง)</span>
                  </option>
                </select>
              </div>
            </div>

            <!-- Customer Info -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div class="space-y-1.5">
                <label class="text-sm font-bold text-text-dark">ชื่อ</label>
                <input v-model="manualForm.name" type="text" placeholder="ระบุชื่อ" required
                  class="w-full bg-sand/30 border border-sand-dark/60 rounded-xl px-4 py-2.5 text-sm transition-all focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none" />
              </div>
              <div class="space-y-1.5">
                <label class="text-sm font-bold text-text-dark">นามสกุล</label>
                <input v-model="manualForm.surname" type="text" placeholder="ระบุนามสกุล" required
                  class="w-full bg-sand/30 border border-sand-dark/60 rounded-xl px-4 py-2.5 text-sm transition-all focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none" />
              </div>
              <div class="space-y-1.5">
                <label class="text-sm font-bold text-text-dark">เบอร์โทรศัพท์</label>
                <input v-model="manualForm.phone" type="tel" placeholder="0XXXXXXXXX" required
                  class="w-full bg-sand/30 border border-sand-dark/60 rounded-xl px-4 py-2.5 text-sm transition-all focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none" />
              </div>
              <div class="space-y-1.5">
                <label class="text-sm font-bold text-text-dark">จำนวนคน</label>
                <input v-model.number="manualForm.passenger_count" type="number" min="1" required
                  class="w-full bg-sand/30 border border-sand-dark/60 rounded-xl px-4 py-2.5 text-sm transition-all focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none" />
              </div>
            </div>

            <!-- Status -->
            <div class="space-y-1.5">
              <label class="text-sm font-bold text-text-dark">สถานะเบื้องต้น</label>
              <div class="flex gap-4">
                <label class="flex items-center gap-2 cursor-pointer p-3 border border-sand-dark/50 rounded-xl flex-1 bg-sand/10 transition-all hover:bg-sand/20" :class="manualForm.status === 'pending' ? 'border-accent ring-1 ring-accent' : ''">
                  <input type="radio" v-model="manualForm.status" value="pending" class="w-4 h-4 text-accent focus:ring-accent" />
                  <span class="text-sm font-medium">รอดำเนินการ (ยังไม่จ่ายเงิน)</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer p-3 border border-sand-dark/50 rounded-xl flex-1 bg-sand/10 transition-all hover:bg-sand/20" :class="manualForm.status === 'confirmed' ? 'border-accent ring-1 ring-accent' : ''">
                  <input type="radio" v-model="manualForm.status" value="confirmed" class="w-4 h-4 text-accent focus:ring-accent" />
                  <span class="text-sm font-medium text-emerald-600">ยืนยันแล้ว (จ่ายเงินครบแล้ว)</span>
                </label>
              </div>
            </div>

            <div class="pt-4 border-t border-sand-dark/50 flex justify-end gap-3">
              <button type="button" @click="showManualModal = false" 
                class="px-5 py-2.5 rounded-xl border border-sand-dark/60 bg-white text-text-dark font-medium hover:bg-sand transition-all text-sm">
                ยกเลิก
              </button>
              <button type="submit" :disabled="submitting"
                class="px-8 py-2.5 rounded-xl bg-accent text-white font-bold hover:bg-accent-mid shadow-lg shadow-accent/20 hover:shadow-xl transition-all disabled:opacity-70 flex items-center gap-2 text-sm">
                <span v-if="submitting" class="material-symbols-rounded animate-spin text-[20px]">sync</span>
                <span v-else class="material-symbols-rounded text-[20px]">check_circle</span>
                สร้างการจองและออก QR Code
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useAdminStore } from '../../stores/admin';
import api from '../../lib/axios';

const admin = useAdminStore();
const filters = reactive({ search: '', status: '', date: '', booking_type: '' });
const showDetail = ref(false);
const showStatusModal = ref(false);
const showManualModal = ref(false);
const detailBooking = ref(null);
const statusBooking = ref(null);
const submitting = ref(false);
const statusForm = reactive({ status: '', reason: '' });

const allTrips = ref([]);
const availableSchedules = ref([]);
const manualForm = reactive({
  trip_id: '',
  schedule_id: '',
  name: '',
  surname: '',
  phone: '',
  passenger_count: 1,
  status: 'pending'
});

const statusLabels = { pending: 'รอดำเนินการ', confirmed: 'ยืนยันแล้ว', cancelled: 'ยกเลิก', refunded: 'คืนเงินแล้ว' };

const statusClass = (status) => {
  const classes = {
    pending: 'bg-yellow-100 text-yellow-800 border border-yellow-200',
    confirmed: 'bg-green-100 text-green-800 border border-green-200',
    cancelled: 'bg-red-100 text-red-800 border border-red-200',
    refunded: 'bg-purple-100 text-purple-800 border border-purple-200'
  };
  return classes[status] || 'bg-gray-100 text-gray-800 border border-gray-200';
};

const getRemainingInstallments = (booking) => {
  if (!booking.installment_payments) return 0;
  const paidCount = booking.installment_payments.filter(p => p.status === 'paid').length;
  return Math.max(0, booking.installment_count - paidCount);
};

const formatMoney = (amount) => new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', minimumFractionDigits: 0 }).format(amount || 0);
const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
};
const formatDateTime = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleString('th-TH', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
};
const paymentMethodLabel = (method) => {
  const labels = { promptpay: 'พร้อมเพย์', mobile_banking: 'Mobile Banking', credit_card: 'บัตรเครดิต', manual: 'แอดมินสร้างให้' };
  return labels[method] || method || '-';
};

let debounceTimer = null;
const debouncedFetch = () => { clearTimeout(debounceTimer); debounceTimer = setTimeout(() => fetchData(), 300); };
const fetchData = (page = 1) => admin.fetchBookings({ ...filters, page });
const goPage = (page) => fetchData(page);

const openDetail = (b) => { detailBooking.value = b; showDetail.value = true; };

const openStatusModal = (b) => {
  statusBooking.value = b;
  statusForm.status = b.status;
  statusForm.reason = '';
  showStatusModal.value = true;
};

const doUpdateStatus = async () => {
  submitting.value = true;
  try {
    await admin.updateBookingStatus(statusBooking.value.booking_ref, statusForm.status, statusForm.reason);
    showStatusModal.value = false;
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาด');
  } finally {
    submitting.value = false;
  }
};

const openManualBookingModal = async () => {
  showManualModal.value = true;
  manualForm.trip_id = '';
  manualForm.schedule_id = '';
  manualForm.name = '';
  manualForm.surname = '';
  manualForm.phone = '';
  manualForm.passenger_count = 1;
  manualForm.status = 'pending';
  
  if (allTrips.value.length === 0) {
    try {
      const res = await api.get('/admin/trips', { params: { per_page: 100, status: 'active' } });
      allTrips.value = res.data.data;
    } catch (e) {
      console.error('Failed to fetch trips', e);
    }
  }
};

const onTripChange = async () => {
  manualForm.schedule_id = '';
  availableSchedules.value = [];
  if (!manualForm.trip_id) return;
  
  try {
    const res = await api.get('/admin/schedules', { params: { trip_id: manualForm.trip_id, upcoming: 1, per_page: 100 } });
    availableSchedules.value = res.data.data;
  } catch (e) {
    console.error('Failed to fetch schedules', e);
  }
};

const doCreateManualBooking = async () => {
  submitting.value = true;
  try {
    const res = await admin.createManualBooking(manualForm);
    showManualModal.value = false;
    // Open detail of the new booking to show QR code
    detailBooking.value = res.data;
    showDetail.value = true;
    fetchData();
  } catch (e) {
    alert(e.response?.data?.message || 'เกิดข้อผิดพลาดในการสร้างการจอง');
  } finally {
    submitting.value = false;
  }
};

onMounted(() => fetchData());
</script>

<style scoped>
/* Optional custom scrollbar for modal */
.custom-scrollbar::-webkit-scrollbar {
  width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: rgba(0, 0, 0, 0.1);
  border-radius: 10px;
}
</style>
