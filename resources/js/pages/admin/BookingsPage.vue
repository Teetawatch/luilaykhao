<template>
  <div class="space-y-6 pb-10">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h1 class="font-anuphan text-2xl font-bold text-text-dark flex items-center gap-3">
          <div class="w-10 h-10 bg-accent/10 rounded-xl flex items-center justify-center text-accent">
            <i class="fas fa-ticket-alt"></i>
          </div>
          จัดการการจอง
        </h1>
        <p class="text-sm text-text-muted mt-1 ml-[52px]">ดูและจัดการการจองทั้งหมดในระบบ</p>
      </div>
    </div>

    <!-- Filters -->
    <div class="bg-white p-4 rounded-2xl shadow-sm border border-sand-dark/50 flex flex-col sm:flex-row gap-4">
      <div class="relative flex-1">
        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
          <i class="fas fa-search text-text-muted/60"></i>
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
                {{ b.schedule?.trip?.title || '-' }}
              </td>
              <td class="px-6 py-4 text-sm font-semibold text-text-dark text-right">
                {{ formatMoney(b.total_amount) }}
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
                    <i class="fas fa-eye"></i>
                  </button>
                  <button @click="openStatusModal(b)" class="w-8 h-8 rounded-lg bg-sand/50 border border-transparent flex items-center justify-center transition-all"
                    :class="b.status === 'pending' ? 'text-accent hover:bg-accent/10 hover:border-accent/20' : 'text-red-500 hover:bg-red-50 hover:border-red-200'" title="เปลี่ยนสถานะ">
                    <i class="fas fa-exchange-alt"></i>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!admin.bookings.data?.length">
              <td colspan="7" class="px-6 py-12 text-center text-text-muted text-sm">
                <i class="fas fa-inbox text-3xl mb-3 text-sand-dark block"></i>
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
          <i class="fas fa-chevron-left text-xs"></i>
        </button>
        <span class="text-sm font-medium text-text-muted">
          {{ admin.bookings.meta.current_page }} / {{ admin.bookings.meta.last_page }}
        </span>
        <button :disabled="admin.bookings.meta.current_page >= admin.bookings.meta.last_page" @click="goPage(admin.bookings.meta.current_page + 1)"
          class="w-9 h-9 rounded-xl border border-sand-dark/60 bg-white flex items-center justify-center text-text-muted hover:bg-sand hover:text-accent hover:border-accent/30 disabled:opacity-50 disabled:cursor-not-allowed transition-all">
          <i class="fas fa-chevron-right text-xs"></i>
        </button>
      </div>
    </div>

    <!-- Detail Modal -->
    <div v-if="showDetail" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm transition-opacity" @click.self="showDetail = false">
      <div class="bg-white rounded-3xl w-full max-w-3xl max-h-[90vh] overflow-hidden shadow-2xl flex flex-col border border-sand-dark/20 animate-in fade-in zoom-in-95 duration-200">
        <div class="px-6 py-5 border-b border-sand-dark/50 flex items-center justify-between bg-sand/10">
          <h2 class="font-anuphan text-xl font-bold text-text-dark flex items-center gap-2">
            <i class="fas fa-file-invoice text-accent"></i> รายละเอียดการจอง
          </h2>
          <button @click="showDetail = false" class="w-8 h-8 rounded-full bg-white border border-sand-dark/50 flex items-center justify-center text-text-muted hover:text-red-500 hover:bg-red-50 hover:border-red-200 transition-all">
            <i class="fas fa-times"></i>
          </button>
        </div>
        
        <div class="p-6 overflow-y-auto custom-scrollbar" v-if="detailBooking">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
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
              </div>
            </div>
            <div class="space-y-1">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider">ทริป</label>
              <div class="text-sm font-medium text-text-dark">{{ detailBooking.schedule?.trip?.title || '-' }}</div>
            </div>
            <div class="space-y-1">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider">จำนวนเงินรวม</label>
              <div class="text-sm font-bold text-text-dark">{{ formatMoney(detailBooking.total_amount) }}</div>
            </div>
            <div class="space-y-1">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider">ชำระแล้ว</label>
              <div class="text-sm font-medium text-green-600">{{ formatMoney(detailBooking.paid_amount) }}</div>
            </div>
            <div class="space-y-1">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider">วิธีชำระ</label>
              <div class="text-sm font-medium text-text-dark">{{ detailBooking.payment_method || '-' }}</div>
            </div>
            <div v-if="detailBooking.paid_at" class="space-y-1">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider">ชำระเมื่อ</label>
              <div class="text-sm font-medium text-text-dark">{{ formatDate(detailBooking.paid_at) }}</div>
            </div>
            <div class="space-y-1">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider">เช็คอิน</label>
              <div v-if="detailBooking.checked_in" class="text-sm font-medium text-green-600 flex items-center gap-1.5">
                <i class="fas fa-check-circle"></i> {{ formatDate(detailBooking.checked_in_at) }}
              </div>
              <div v-else class="text-sm font-medium text-text-muted italic">ยังไม่เช็คอิน</div>
            </div>
            
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
            <div v-if="detailBooking.qr_code" class="space-y-1 col-span-1 md:col-span-2">
              <label class="text-xs font-bold text-text-muted uppercase tracking-wider">QR Code</label>
              <div class="font-mono text-xs bg-sand/30 p-2 rounded-lg break-all border border-sand-dark/30">{{ detailBooking.qr_code }}</div>
            </div>
          </div>

          <!-- Passengers -->
          <div v-if="detailBooking.passengers?.length" class="mb-8">
            <h3 class="text-sm font-bold text-text-dark mb-4 flex items-center gap-2 border-b border-sand-dark/50 pb-2">
              <i class="fas fa-users text-accent"></i> ผู้โดยสาร ({{ detailBooking.passengers.length }})
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div v-for="p in detailBooking.passengers" :key="p.id" class="bg-sand/20 border border-sand-dark/50 rounded-xl p-3 flex flex-col gap-1.5 hover:bg-sand/40 transition-colors">
                <div class="font-semibold text-text-dark text-sm">{{ p.name }}</div>
                <div class="flex items-center gap-4 text-xs text-text-muted">
                  <span v-if="p.phone"><i class="fas fa-phone mr-1"></i>{{ p.phone }}</span>
                  <span v-if="p.health_notes" class="text-orange-600"><i class="fas fa-notes-medical mr-1"></i>{{ p.health_notes }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Seats -->
          <div v-if="detailBooking.seats?.length">
            <h3 class="text-sm font-bold text-text-dark mb-4 flex items-center gap-2 border-b border-sand-dark/50 pb-2">
              <i class="fas fa-chair text-accent"></i> ที่นั่ง
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
    <div v-if="showStatusModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm transition-opacity" @click.self="showStatusModal = false">
      <div class="bg-white rounded-3xl w-full max-w-md overflow-hidden shadow-2xl flex flex-col border border-sand-dark/20 animate-in fade-in zoom-in-95 duration-200">
        <div class="px-6 py-5 border-b border-sand-dark/50 flex items-center justify-between bg-sand/10">
          <h2 class="font-anuphan text-xl font-bold text-text-dark flex items-center gap-2">
            <i class="fas fa-exchange-alt text-accent"></i> เปลี่ยนสถานะ
          </h2>
          <button @click="showStatusModal = false" class="w-8 h-8 rounded-full bg-white border border-sand-dark/50 flex items-center justify-center text-text-muted hover:text-red-500 hover:bg-red-50 hover:border-red-200 transition-all">
            <i class="fas fa-times"></i>
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
            <i v-if="submitting" class="fas fa-spinner fa-spin"></i>
            <i v-else class="fas fa-save"></i>
            บันทึกการเปลี่ยนแปลง
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useAdminStore } from '../../stores/admin';

const admin = useAdminStore();
const filters = reactive({ search: '', status: '', date: '' });
const showDetail = ref(false);
const showStatusModal = ref(false);
const detailBooking = ref(null);
const statusBooking = ref(null);
const submitting = ref(false);
const statusForm = reactive({ status: '', reason: '' });

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

const formatMoney = (amount) => new Intl.NumberFormat('th-TH', { style: 'currency', currency: 'THB', minimumFractionDigits: 0 }).format(amount || 0);
const formatDate = (date) => {
  if (!date) return '-';
  return new Date(date).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
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
