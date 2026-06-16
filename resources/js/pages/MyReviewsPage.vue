<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32">
    <div class="max-w-3xl mx-auto px-4 sm:px-6">

      <section class="mb-8 relative">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-2" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
          รีวิวของฉัน
        </h1>
        <p class="text-[#505E5E] text-sm" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
          แบ่งปันประสบการณ์การเดินทางของคุณ
        </p>
      </section>

      <!-- Write Review Modal -->
      <div v-if="showModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 transition-all pr-4 sm:pr-6 md:pr-0">
        <div class="bg-white rounded-[24px] w-full max-w-lg p-6 sm:p-8 shadow-2xl relative">
          <h2 class="text-xl font-bold text-[#1a1c1c] mb-2" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
            {{ editingReview ? 'แก้ไขรีวิว' : 'เขียนรีวิว' }}
          </h2>
          <p class="text-[15px] text-[#505E5E] mb-6 line-clamp-2" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
            {{ editingReview ? editingReview.trip_title : pendingBooking?.schedule?.trip?.title }}
          </p>

          <!-- Star Rating -->
          <div class="mb-6 bg-[#F9FAFA] p-4 rounded-[16px] border border-[#E8EEEF] flex flex-col items-center">
            <p class="text-sm font-bold text-[#1a1c1c] mb-3" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">ให้คะแนนประสบการณ์ของคุณ</p>
            <div class="flex gap-2">
              <button
                v-for="s in 5"
                :key="s"
                @click="form.rating = s"
                class="transition-transform hover:scale-110 focus:outline-none"
                :class="s <= form.rating ? 'text-[#F59E0B]' : 'text-[#D1D5DB]'">
                <span class="material-symbols-rounded text-4xl" :style="s <= form.rating ? 'font-variation-settings:\'FILL\' 1;' : 'font-variation-settings:\'FILL\' 0;'">star</span>
              </button>
            </div>
            <p class="text-sm font-bold mt-3 transition-colors" :class="form.rating ? 'text-[#006565]' : 'text-[#A0B0B0]'" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
              {{ ratingLabels[form.rating] }}
            </p>
          </div>

          <!-- Comment -->
          <div class="mb-5">
            <label class="text-sm font-bold text-[#1a1c1c] mb-2 block" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
              ความคิดเห็น
            </label>
            <textarea
              v-model="form.comment"
              rows="4"
              placeholder="เล่าประสบการณ์การเดินทางของคุณ..."
              class="w-full border border-[#E8EEEF] bg-[#F9FAFA] rounded-[16px] px-4 py-3 text-[15px] text-[#1a1c1c] resize-none focus:outline-none focus:bg-white focus:border-[#006565] focus:ring-4 focus:ring-[#006565]/10 transition-all font-anuphan"
              style="font-family:'DB Heavent', 'Anuphan',sans-serif;"></textarea>
          </div>

          <!-- Image Upload -->
          <div class="mb-8">
            <label class="text-sm font-bold text-[#1a1c1c] mb-3 flex items-center justify-between" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
              <span>สมุดภาพ (สูงสุด 5 รูป)</span>
              <span class="text-xs font-normal text-[#889696]">{{ form.images.length }}/5 รูป</span>
            </label>
            <div class="flex flex-wrap gap-3 mb-2">
              <div
                v-for="(img, i) in form.images"
                :key="i"
                class="relative w-[72px] h-[72px] rounded-[12px] overflow-hidden border border-[#E8EEEF] group shadow-sm z-0">
                <img :src="img" class="w-full h-full object-cover transition-transform group-hover:scale-105" />
                <button
                  @click="form.images.splice(i, 1)"
                  class="absolute top-1 right-1 bg-black/60 text-white rounded-full w-6 h-6 flex items-center justify-center opacity-80 hover:opacity-100 hover:bg-black transition-all">
                  <span class="material-symbols-rounded text-[16px]">close</span>
                </button>
              </div>
              <label
                v-if="form.images.length < 5"
                class="w-[72px] h-[72px] border-2 border-dashed border-[#A0B0B0] bg-[#F9FAFA] rounded-[12px] flex items-center justify-center cursor-pointer hover:border-[#006565] hover:bg-[#E3F2F2] transition-colors group">
                <span class="material-symbols-rounded text-[#A0B0B0] group-hover:text-[#006565] text-[28px]">add_photo_alternate</span>
                <input type="file" accept="image/*" class="hidden" @change="handleImageUpload" :disabled="uploading" />
              </label>
            </div>
            <p v-if="uploading" class="text-[13px] font-bold text-[#006565] animate-pulse flex items-center gap-1.5 mt-2" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
               <span class="material-symbols-rounded text-[16px] animate-spin">progress_activity</span>กำลังอัปโหลด...
            </p>
          </div>

          <div class="flex gap-3">
            <button
              @click="closeModal"
              class="flex-1 bg-white text-[#505E5E] border border-[#E8EEEF] py-3.5 rounded-[16px] font-bold text-[15px] hover:bg-[#F9FAFA] transition-all"
              style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
              ยกเลิก
            </button>
            <button
              @click="submitReview"
              :disabled="!form.rating || submitting"
              class="flex-1 bg-[#006565] text-white py-3.5 rounded-[16px] font-bold text-[15px] disabled:opacity-50 disabled:cursor-not-allowed hover:bg-[#004f4f] transition-all shadow-sm shadow-[#006565]/20 flex justify-center items-center gap-2"
              style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
              <span v-if="submitting" class="material-symbols-rounded text-[20px] animate-spin border-0">progress_activity</span>
              {{ submitting ? 'กำลังบันทึก...' : (editingReview ? 'บันทึก' : 'ส่งรีวิว') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="flex flex-col items-center justify-center py-24 space-y-4">
        <div class="w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
        <p class="text-[#505E5E] font-medium animate-pulse text-sm" style="font-family: 'DB Heavent', 'Anuphan', sans-serif;">กำลังโหลดข้อมูลรีวิว...</p>
      </div>

      <template v-else>
        <!-- Pending Reviews -->
        <div v-if="pendingBookings.length > 0" class="mb-10">
          <h2 class="text-xl font-bold text-[#1a1c1c] mb-4 flex items-center gap-2" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
            <span class="material-symbols-rounded text-[#D97706]">pending_actions</span>
            รอการรีวิว ({{ pendingBookings.length }})
          </h2>
          <div class="space-y-4">
            <div
              v-for="b in pendingBookings"
              :key="b.id"
              class="bg-white rounded-[20px] p-5 flex flex-col sm:flex-row items-start sm:items-center gap-4 border border-[#E8EEEF] hover:shadow-md transition-all shadow-sm">
              <div class="flex items-center gap-4 w-full sm:w-auto flex-1 min-w-0">
                <img
                  v-if="b.schedule?.trip?.thumbnail_image || b.schedule?.trip?.cover_image"
                  :src="b.schedule.trip.thumbnail_image || b.schedule.trip.cover_image"
                  class="w-[72px] h-[72px] rounded-[16px] object-cover shrink-0 border border-[#E8EEEF]" />
                <div v-else class="w-[72px] h-[72px] rounded-[16px] bg-[#F4F7F6] flex justify-center items-center shrink-0 border border-[#E8EEEF]">
                   <span class="material-symbols-rounded text-[#A0B0B0] text-3xl">image_not_supported</span>
                </div>
                <div class="flex-1 min-w-0 pr-4">
                  <p class="font-bold text-[15px] text-[#1a1c1c] line-clamp-2" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                    {{ b.schedule?.trip?.title }}
                  </p>
                  <p class="text-[13px] text-[#505E5E] mt-1 flex items-center gap-1.5" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                    <span class="material-symbols-rounded text-[16px] text-[#A0B0B0]">calendar_month</span>
                    {{ formatDate(b.schedule?.departure_date) }}
                  </p>
                </div>
              </div>
              <button
                @click="openWriteReview(b)"
                class="w-full sm:w-auto shrink-0 bg-white border-2 border-[#006565] text-[#006565] px-5 py-2.5 rounded-[12px] text-sm font-bold hover:bg-[#E3F2F2] transition-colors flex items-center justify-center gap-1.5"
                style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                <span class="material-symbols-rounded text-[18px]">edit_square</span>
                เขียนรีวิว
              </button>
            </div>
          </div>
        </div>

        <!-- My Reviews -->
        <div>
          <h2 class="text-xl font-bold text-[#1a1c1c] mb-4 flex items-center gap-2" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
            <span class="material-symbols-rounded text-[#006565]">reviews</span>
            รีวิวที่เขียนแล้ว ({{ myReviews.length }})
          </h2>

           <div v-if="myReviews.length === 0 && pendingBookings.length === 0" class="text-center py-20 bg-white rounded-[24px] shadow-sm border border-[#E8EEEF] flex flex-col items-center justify-center">
             <div class="w-20 h-20 bg-[#F4F7F6] rounded-full flex items-center justify-center mb-5">
               <span class="material-symbols-rounded text-4xl text-[#A0B0B0]">rate_review</span>
             </div>
             <h3 class="text-lg font-bold text-[#1a1c1c] mb-2" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">คุณยังไม่ได้เขียนรีวิว</h3>
             <p class="text-[#505E5E] text-sm mb-6 max-w-sm mx-auto" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
               เมื่อคุณเดินทางร่วมกับเราเสร็จสิ้น คุณจะสามารถเขียนรีวิวบอกเล่าเรื่องราวความประทับใจได้ที่นี่
             </p>
             <router-link to="/my-bookings"
                class="inline-flex items-center gap-2 bg-[#006565] text-white px-6 py-3 rounded-full font-bold text-sm hover:bg-[#004f4f] transition-all"
                style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
               ไปดูการจองของฉัน
               <span class="material-symbols-rounded text-[20px]">arrow_forward</span>
             </router-link>
           </div>

          <div class="space-y-4">
            <div
              v-for="r in myReviews"
              :key="r.id"
              class="bg-white rounded-[20px] p-5 md:p-6 border border-[#E8EEEF] hover:shadow-md hover:border-[#006565]/30 transition-all shadow-sm">
              
              <div class="flex flex-col sm:flex-row justify-between items-start gap-4 mb-4">
                <div class="flex-1 min-w-0">
                  <p class="font-bold text-[16px] text-[#1a1c1c] mb-1.5" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ r.trip_title }}</p>
                  <div class="flex gap-1 items-center">
                    <span v-for="s in 5" :key="s" class="material-symbols-rounded text-[20px]" :class="s <= r.rating ? 'text-[#F59E0B]' : 'text-[#D1D5DB]'" :style="s <= r.rating ? 'font-variation-settings:\'FILL\' 1;' : 'font-variation-settings:\'FILL\' 0;'">star</span>
                  </div>
                </div>
                <div class="flex gap-2 shrink-0 bg-[#F4F7F6] p-1 rounded-[12px] border border-[#E8EEEF]">
                  <button @click="openEditReview(r)" class="flex items-center justify-center p-1.5 text-[#006565] hover:bg-white rounded-[8px] transition-colors" title="แก้ไข">
                    <span class="material-symbols-rounded text-[20px]">edit</span>
                  </button>
                  <button @click="deleteReview(r.id)" class="flex items-center justify-center p-1.5 text-[#DC2626] hover:bg-[#FEF2F2] rounded-[8px] transition-colors" title="ลบ">
                    <span class="material-symbols-rounded text-[20px]">delete</span>
                  </button>
                </div>
              </div>

              <p v-if="r.comment" class="text-[15px] text-[#505E5E] mb-4 leading-relaxed whitespace-pre-wrap" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ r.comment }}</p>

              <!-- Review Images -->
              <div v-if="r.images?.length" class="flex gap-2.5 mb-4 flex-wrap">
                <img v-for="(img, i) in r.images" :key="i" :src="img"
                  class="w-[84px] h-[84px] rounded-[16px] object-cover border border-[#E8EEEF] cursor-pointer hover:opacity-90 transition-opacity" />
              </div>

              <!-- Admin Reply -->
              <div v-if="r.admin_reply" class="bg-[#F9FAFA] rounded-[16px] p-4 border border-[#E8EEEF] mt-2 relative overflow-hidden">
                <div class="absolute left-0 top-0 bottom-0 w-1 bg-[#006565]"></div>
                <div class="flex items-center gap-1.5 mb-1.5">
                  <span class="material-symbols-rounded text-[18px] text-[#006565]">forum</span>
                  <p class="text-[13px] font-bold text-[#006565]" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                    ตอบกลับโดยทีมงาน
                  </p>
                </div>
                <p class="text-[14px] text-[#1a1c1c] leading-relaxed whitespace-pre-wrap" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">{{ r.admin_reply }}</p>
              </div>

              <div class="flex items-center gap-1.5 mt-4 pt-4 border-t border-[#F4F7F6]">
                 <span class="material-symbols-rounded text-[#A0B0B0] text-[16px]">schedule</span>
                 <p class="text-[12px] font-bold text-[#889696] uppercase" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
                   {{ formatDate(r.created_at) }}
                 </p>
              </div>

            </div>
          </div>
        </div>
      </template>

    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import api from '../lib/axios';
import { useBookingStore } from '../stores/booking';

const bookingStore = useBookingStore();

const loading = ref(true);
const myReviews = ref([]);
const pendingBookings = ref([]);
const showModal = ref(false);
const submitting = ref(false);
const uploading = ref(false);
const editingReview = ref(null);
const pendingBooking = ref(null);

const form = ref({ rating: 0, comment: '', images: [] });

const ratingLabels = {
  0: 'เลือกคะแนน',
  1: 'แย่มาก',
  2: 'แย่',
  3: 'พอใช้',
  4: 'ดี',
  5: 'ยอดเยี่ยม',
};

async function loadData() {
  loading.value = true;
  try {
    await bookingStore.fetchMyBookings();
    const reviewsRes = await api.get('/reviews/my');
    myReviews.value = reviewsRes.data.data;

    const reviewedBookingIds = new Set(myReviews.value.map(r => r.booking_id));
    pendingBookings.value = bookingStore.bookings.filter(
      b => b.status === 'confirmed' && !reviewedBookingIds.has(b.id) && isBookingReviewAvailable(b)
    );
  } finally {
    loading.value = false;
  }
}

function isBookingReviewAvailable(booking) {
  if (typeof booking.can_review === 'boolean') {
    return booking.can_review;
  }

  const availableAt = booking.schedule?.review_available_at || getReviewAvailableAt(booking.schedule);
  return availableAt ? Date.now() >= new Date(availableAt).getTime() : false;
}

function getReviewAvailableAt(schedule) {
  const date = schedule?.return_date || schedule?.departure_date;
  if (!date) return null;

  return `${date}T20:00:00+07:00`;
}

function openWriteReview(booking) {
  if (!isBookingReviewAvailable(booking)) {
    alert('สามารถรีวิวได้หลังจบทริปวันสุดท้าย เวลา 20:00 น. เป็นต้นไป');
    return;
  }

  editingReview.value = null;
  pendingBooking.value = booking;
  form.value = { rating: 0, comment: '', images: [] };
  showModal.value = true;
}

function openEditReview(review) {
  editingReview.value = review;
  pendingBooking.value = null;
  form.value = { rating: review.rating, comment: review.comment || '', images: [...(review.images || [])] };
  showModal.value = true;
}

function closeModal() {
  showModal.value = false;
  editingReview.value = null;
  pendingBooking.value = null;
}

async function handleImageUpload(e) {
  const file = e.target.files[0];
  if (!file) return;
  uploading.value = true;
  try {
    const fd = new FormData();
    fd.append('image', file);
    const res = await api.post('/reviews/upload-image', fd);
    form.value.images.push(res.data.data.url);
  } catch {
    alert('อัปโหลดรูปไม่สำเร็จ');
  } finally {
    uploading.value = false;
  }
}

async function submitReview() {
  if (!form.value.rating) return;
  submitting.value = true;
  try {
    if (editingReview.value) {
      await api.put(`/reviews/${editingReview.value.id}`, {
        rating: form.value.rating,
        comment: form.value.comment,
        images: form.value.images,
      });
    } else {
      await api.post('/reviews', {
        booking_id: pendingBooking.value.id,
        rating: form.value.rating,
        comment: form.value.comment,
        images: form.value.images,
      });
    }
    closeModal();
    await loadData();
  } catch (e) {
    alert(e?.response?.data?.message || 'บันทึกไม่สำเร็จ');
  } finally {
    submitting.value = false;
  }
}

async function deleteReview(id) {
  if (!confirm('ต้องการลบรีวิวนี้หรือไม่?')) return;
  try {
    await api.delete(`/reviews/${id}`);
    await loadData();
  } catch {
    alert('ลบไม่สำเร็จ');
  }
}

function formatDate(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('th-TH', { day: 'numeric', month: 'long', year: 'numeric' });
}

onMounted(loadData);
</script>
