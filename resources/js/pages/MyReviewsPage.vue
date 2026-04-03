<template>
  <div class="min-h-screen bg-[#f9f9f9] pt-8 pb-32">
    <div class="max-w-4xl mx-auto px-4 sm:px-6">

      <section class="mb-8">
        <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight mb-1" style="font-family:'Anuphan',sans-serif;">
          รีวิวของฉัน
        </h1>
        <p class="text-[#3e4949]" style="font-family:'Anuphan',sans-serif;">
          แบ่งปันประสบการณ์การเดินทางของคุณ
        </p>
      </section>

      <!-- Write Review Modal -->
      <div v-if="showModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-lg p-6 shadow-2xl">
          <h2 class="text-xl font-bold text-[#1a1c1c] mb-1" style="font-family:'Anuphan',sans-serif;">
            {{ editingReview ? 'แก้ไขรีวิว' : 'เขียนรีวิว' }}
          </h2>
          <p class="text-sm text-[#3e4949] mb-5" style="font-family:'Anuphan',sans-serif;">
            {{ editingReview ? editingReview.trip_title : pendingBooking?.schedule?.trip?.title }}
          </p>

          <!-- Star Rating -->
          <div class="mb-5">
            <p class="text-sm font-semibold text-[#3e4949] mb-2" style="font-family:'Anuphan',sans-serif;">คะแนน</p>
            <div class="flex gap-2">
              <button
                v-for="s in 5"
                :key="s"
                @click="form.rating = s"
                class="text-3xl transition-transform hover:scale-110"
                :class="s <= form.rating ? 'text-yellow-400' : 'text-gray-300'">
                ★
              </button>
            </div>
            <p class="text-xs text-[#3e4949] mt-1" style="font-family:'Anuphan',sans-serif;">
              {{ ratingLabels[form.rating] }}
            </p>
          </div>

          <!-- Comment -->
          <div class="mb-5">
            <label class="text-sm font-semibold text-[#3e4949] mb-2 block" style="font-family:'Anuphan',sans-serif;">
              ความคิดเห็น
            </label>
            <textarea
              v-model="form.comment"
              rows="4"
              placeholder="เล่าประสบการณ์การเดินทางของคุณ..."
              class="w-full border border-[#e2e2e2] rounded-xl px-4 py-3 text-sm text-[#1a1c1c] resize-none focus:outline-none focus:border-[#006565]"
              style="font-family:'Anuphan',sans-serif;"></textarea>
          </div>

          <!-- Image Upload -->
          <div class="mb-6">
            <label class="text-sm font-semibold text-[#3e4949] mb-2 block" style="font-family:'Anuphan',sans-serif;">
              รูปภาพ (สูงสุด 5 รูป)
            </label>
            <div class="flex flex-wrap gap-2 mb-2">
              <div
                v-for="(img, i) in form.images"
                :key="i"
                class="relative w-20 h-20 rounded-xl overflow-hidden border border-[#e2e2e2]">
                <img :src="img" class="w-full h-full object-cover" />
                <button
                  @click="form.images.splice(i, 1)"
                  class="absolute top-1 right-1 bg-black/50 text-white rounded-full w-5 h-5 text-xs flex items-center justify-center">
                  ×
                </button>
              </div>
              <label
                v-if="form.images.length < 5"
                class="w-20 h-20 border-2 border-dashed border-[#bdc9c8] rounded-xl flex items-center justify-center cursor-pointer hover:border-[#006565] transition-colors">
                <span class="text-2xl text-[#bdc9c8]">+</span>
                <input type="file" accept="image/*" class="hidden" @change="handleImageUpload" :disabled="uploading" />
              </label>
            </div>
            <p v-if="uploading" class="text-xs text-[#006565]" style="font-family:'Anuphan',sans-serif;">กำลังอัปโหลด...</p>
          </div>

          <div class="flex gap-3">
            <button
              @click="submitReview"
              :disabled="!form.rating || submitting"
              class="flex-1 bg-[#006565] text-white py-3 rounded-xl font-semibold text-sm disabled:opacity-50 hover:opacity-90 transition-opacity"
              style="font-family:'Anuphan',sans-serif;">
              {{ submitting ? 'กำลังบันทึก...' : (editingReview ? 'บันทึกการแก้ไข' : 'ส่งรีวิว') }}
            </button>
            <button
              @click="closeModal"
              class="flex-1 bg-[#f3f3f3] text-[#1a1c1c] py-3 rounded-xl font-semibold text-sm hover:bg-[#e8e8e8] transition-colors"
              style="font-family:'Anuphan',sans-serif;">
              ยกเลิก
            </button>
          </div>
        </div>
      </div>

      <!-- Loading -->
      <div v-if="loading" class="text-center py-20">
        <div class="inline-block w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
      </div>

      <template v-else>
        <!-- Pending Reviews (bookings needing review) -->
        <div v-if="pendingBookings.length > 0" class="mb-8">
          <h2 class="text-lg font-bold text-[#1a1c1c] mb-4" style="font-family:'Anuphan',sans-serif;">
            รอการรีวิว ({{ pendingBookings.length }})
          </h2>
          <div class="space-y-3">
            <div
              v-for="b in pendingBookings"
              :key="b.id"
              class="bg-white rounded-2xl p-4 flex items-center gap-4"
              style="box-shadow:0px 4px 16px rgba(0,64,64,0.06);">
              <img
                v-if="b.schedule?.trip?.image_url"
                :src="b.schedule.trip.image_url"
                class="w-16 h-16 rounded-xl object-cover shrink-0" />
              <div v-else class="w-16 h-16 rounded-xl bg-[#e8e8e8] shrink-0"></div>
              <div class="flex-1 min-w-0">
                <p class="font-bold text-[#1a1c1c] truncate" style="font-family:'Anuphan',sans-serif;">
                  {{ b.schedule?.trip?.title }}
                </p>
                <p class="text-sm text-[#3e4949]" style="font-family:'Anuphan',sans-serif;">
                  {{ formatDate(b.schedule?.departure_date) }}
                </p>
              </div>
              <button
                @click="openWriteReview(b)"
                class="shrink-0 bg-[#006565] text-white px-4 py-2 rounded-xl text-sm font-semibold hover:opacity-90 transition-opacity"
                style="font-family:'Anuphan',sans-serif;">
                เขียนรีวิว
              </button>
            </div>
          </div>
        </div>

        <!-- My Reviews -->
        <div>
          <h2 class="text-lg font-bold text-[#1a1c1c] mb-4" style="font-family:'Anuphan',sans-serif;">
            รีวิวที่เขียนแล้ว ({{ myReviews.length }})
          </h2>

          <div v-if="myReviews.length === 0 && pendingBookings.length === 0" class="text-center py-20">
            <p class="text-[#3e4949] text-lg" style="font-family:'Anuphan',sans-serif;">ยังไม่มีรีวิว</p>
            <router-link to="/my-bookings" class="inline-block mt-4 text-[#006565] font-semibold text-sm" style="font-family:'Anuphan',sans-serif;">
              ไปดูการจองของฉัน →
            </router-link>
          </div>

          <div class="space-y-4">
            <div
              v-for="r in myReviews"
              :key="r.id"
              class="bg-white rounded-2xl p-5"
              style="box-shadow:0px 4px 16px rgba(0,64,64,0.06);">
              <div class="flex justify-between items-start mb-3">
                <div>
                  <p class="font-bold text-[#1a1c1c]" style="font-family:'Anuphan',sans-serif;">{{ r.trip_title }}</p>
                  <div class="flex gap-0.5 mt-1">
                    <span v-for="s in 5" :key="s" class="text-lg" :class="s <= r.rating ? 'text-yellow-400' : 'text-gray-300'">★</span>
                  </div>
                </div>
                <div class="flex gap-2">
                  <button @click="openEditReview(r)" class="text-sm text-[#006565] font-semibold" style="font-family:'Anuphan',sans-serif;">แก้ไข</button>
                  <button @click="deleteReview(r.id)" class="text-sm text-red-500 font-semibold" style="font-family:'Anuphan',sans-serif;">ลบ</button>
                </div>
              </div>

              <p v-if="r.comment" class="text-sm text-[#3e4949] mb-3" style="font-family:'Anuphan',sans-serif;">{{ r.comment }}</p>

              <!-- Review Images -->
              <div v-if="r.images?.length" class="flex gap-2 mb-3 flex-wrap">
                <img v-for="(img, i) in r.images" :key="i" :src="img"
                  class="w-20 h-20 rounded-xl object-cover border border-[#e2e2e2]" />
              </div>

              <!-- Admin Reply -->
              <div v-if="r.admin_reply" class="bg-[#f0fafa] rounded-xl p-3 border-l-4 border-[#006565]">
                <p class="text-xs font-bold text-[#006565] mb-1" style="font-family:'Anuphan',sans-serif;">
                  ตอบกลับโดยทีมงาน
                </p>
                <p class="text-sm text-[#3e4949]" style="font-family:'Anuphan',sans-serif;">{{ r.admin_reply }}</p>
              </div>

              <p class="text-xs text-[#bdc9c8] mt-3" style="font-family:'Anuphan',sans-serif;">
                {{ formatDate(r.created_at) }}
              </p>
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
      b => b.status === 'confirmed' && !reviewedBookingIds.has(b.id)
    );
  } finally {
    loading.value = false;
  }
}

function openWriteReview(booking) {
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
