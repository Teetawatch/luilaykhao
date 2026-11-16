<template>
  <div class="font-anuphan bg-[var(--color-sand)] min-h-screen pb-24">
    <div v-if="loading" aria-hidden="true">
      <div class="h-[46vh] min-h-[340px] shimmer"></div>
    </div>

    <div v-else-if="notFound" class="max-w-xl mx-auto px-4 pt-24 text-center">
      <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-[var(--color-accent-tint)] text-[var(--color-accent)]">
        <span class="material-symbols-rounded icon-32">cottage</span>
      </span>
      <p class="mt-4 text-xl font-extrabold text-[var(--color-text-dark)]">ไม่พบที่พักนี้ หรือปิดรับจองชั่วคราว</p>
      <router-link to="/stays" class="mt-6 inline-flex rounded-full bg-[var(--color-accent)] px-6 py-3 text-sm font-bold text-white press">ดูที่พักทั้งหมด</router-link>
    </div>

    <template v-else-if="stay">
      <!-- ── ภาพปก ── -->
      <section class="relative flex h-[46vh] min-h-[340px] flex-col overflow-hidden bg-[var(--color-primary)]">
        <img v-if="stay.cover_image" :src="stay.cover_image" :alt="stay.name" fetchpriority="high" class="absolute inset-0 h-full w-full object-cover" />
        <div class="absolute inset-0 bg-gradient-to-t from-black/85 via-black/25 to-black/40"></div>
        <nav aria-label="เส้นทางนำทาง" class="relative z-10 mx-auto flex w-full max-w-6xl items-center gap-1 px-4 pt-6 text-[13px] font-semibold text-white/75 sm:px-6 md:px-8">
          <router-link to="/stays" class="hover:text-white">ที่พักชุมชน</router-link>
          <span class="material-symbols-rounded icon-16 opacity-60">chevron_right</span>
          <span class="truncate text-white">{{ stay.name }}</span>
        </nav>
        <div class="flex-1"></div>
        <div class="relative z-10 mx-auto w-full max-w-6xl px-4 pb-10 sm:px-6 md:px-8">
          <div class="flex flex-wrap gap-2">
            <span class="hero-tag">{{ stay.type_label }}</span>
            <span class="hero-tag hero-tag--accent">
              <span class="material-symbols-rounded icon-16">volunteer_activism</span>
              {{ Math.round(stay.community_share_percent) }}% ของค่าที่พักถึงมือชุมชน
            </span>
          </div>
          <h1 class="mt-4 text-3xl font-black leading-tight tracking-tight text-white sm:text-4xl md:text-5xl">{{ stay.name }}</h1>
          <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[15px] font-semibold text-white/85">
            <span v-if="stay.community_name">{{ stay.community_name }}</span>
            <span class="inline-flex items-center gap-1"><span class="material-symbols-rounded icon-18">location_on</span>{{ [stay.district, stay.province].filter(Boolean).join(' · ') }}</span>
            <span v-if="stay.rating_count" class="inline-flex items-center gap-1"><span class="material-symbols-rounded icon-18 text-amber-400">star</span>{{ stay.rating_avg.toFixed(1) }} ({{ stay.rating_count }} รีวิว)</span>
          </p>
        </div>
      </section>

      <div class="mx-auto max-w-6xl px-4 sm:px-6 md:px-8 mt-8 grid gap-8 lg:grid-cols-[1fr_380px]">
        <!-- ── เนื้อหา ── -->
        <div class="min-w-0 space-y-10">
          <section v-if="stay.summary || stay.description">
            <h2 class="section-kicker">ที่พักนี้</h2>
            <p v-if="stay.summary" class="mt-3 text-lg font-bold text-[var(--color-text-dark)]">{{ stay.summary }}</p>
            <p v-if="stay.description" class="mt-3 whitespace-pre-line leading-relaxed text-[var(--color-text-mid)]">{{ stay.description }}</p>
            <ul v-if="stay.highlights.length" class="mt-4 grid gap-2 sm:grid-cols-2">
              <li v-for="h in stay.highlights" :key="h" class="flex gap-2 text-[15px] text-[var(--color-text-dark)]">
                <span class="material-symbols-rounded icon-20 text-[var(--color-accent)]">check_circle</span>{{ h }}
              </li>
            </ul>
          </section>

          <div v-if="stay.gallery.length" class="gallery">
            <a v-for="(url, i) in stay.gallery.slice(0, 7)" :key="url + i" :href="url" target="_blank" class="gallery__item overflow-hidden">
              <img :src="url" :alt="`${stay.name} รูปที่ ${i + 1}`" loading="lazy" />
            </a>
          </div>

          <section v-if="stay.community_story" class="community">
            <span class="material-symbols-rounded community__icon">diversity_3</span>
            <h2 class="text-xl font-black text-[var(--color-text-dark)]">เรื่องของชุมชน</h2>
            <p class="mt-3 whitespace-pre-line leading-relaxed text-[var(--color-text-mid)]">{{ stay.community_story }}</p>
            <p class="mt-4 text-sm font-bold text-[var(--color-accent)]">
              ทุกการจอง {{ Math.round(stay.community_share_percent) }}% ของยอดโอนให้เจ้าของที่พักหลังคุณเข้าพัก
            </p>
          </section>

          <section v-if="stay.amenities.length">
            <h2 class="section-kicker">สิ่งอำนวยความสะดวก</h2>
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
              <div v-for="a in stay.amenities" :key="a.key" class="flex items-center gap-2 text-[15px] font-semibold text-[var(--color-text-dark)]">
                <span class="material-symbols-rounded icon-22 text-[var(--color-accent)]">{{ a.icon }}</span>{{ a.label }}
              </div>
            </div>
          </section>

          <section>
            <h2 class="section-kicker">ห้องพัก</h2>
            <div class="mt-4 grid gap-4">
              <article v-for="room in roomRows" :key="room.id" class="room" :class="{ 'room--picked': picked?.id === room.id }">
                <img v-if="room.photos[0]" :src="room.photos[0]" :alt="room.name" class="room__img" loading="lazy" />
                <div class="min-w-0 flex-1">
                  <h3 class="text-lg font-extrabold text-[var(--color-text-dark)]">{{ room.name }}</h3>
                  <p class="mt-0.5 text-[13px] font-semibold text-[var(--color-text-muted)]">
                    พักได้ {{ room.max_guests }} คน/ห้อง<span v-if="room.base_guests < room.max_guests"> (ราคารวม {{ room.base_guests }} คน)</span>
                    <span v-if="room.breakfast_included"> · รวมอาหารเช้า</span>
                  </p>
                  <p v-if="room.description" class="mt-2 text-[14px] text-[var(--color-text-mid)]">{{ room.description }}</p>
                  <div class="mt-3 flex flex-wrap items-end justify-between gap-3">
                    <div>
                      <template v-if="room.quote">
                        <span class="text-xl font-black text-[var(--color-text-dark)]">฿{{ money(room.quote.total_amount) }}</span>
                        <span class="text-[13px] font-semibold text-[var(--color-text-muted)]"> · {{ room.quote.nights }} คืน{{ room.units_needed > 1 ? ` · ${room.units_needed} ห้อง` : '' }}</span>
                      </template>
                      <template v-else>
                        <span class="text-xl font-black text-[var(--color-text-dark)]">฿{{ money(room.price_per_night) }}</span>
                        <span class="text-[13px] font-semibold text-[var(--color-text-muted)]"> /คืน</span>
                      </template>
                      <p v-if="room.weekend_price && !room.quote" class="text-[12px] text-[var(--color-text-muted)]">คืนศุกร์–เสาร์ ฿{{ money(room.weekend_price) }}</p>
                    </div>
                    <template v-if="availability">
                      <span v-if="room.available_units < room.units_needed" class="text-sm font-bold text-red-600">เต็มในวันที่เลือก</span>
                      <button v-else type="button" class="pick-btn press" @click="pick(room)">
                        {{ picked?.id === room.id ? 'เลือกแล้ว' : 'เลือกห้องนี้' }}
                      </button>
                    </template>
                  </div>
                </div>
              </article>
            </div>
          </section>

          <section v-if="stay.experiences.length">
            <h2 class="section-kicker">กิจกรรมกับชุมชน</h2>
            <p class="mt-2 text-sm text-[var(--color-text-muted)]">จองพ่วงไปกับที่พักได้ รายได้เข้าชุมชนเหมือนค่าห้อง</p>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
              <label v-for="x in stay.experiences" :key="x.id" class="exp" :class="{ 'exp--on': chosenExperiences.includes(x.id) }">
                <input type="checkbox" :value="x.id" v-model="chosenExperiences" class="sr-only" @change="refreshQuote" />
                <img v-if="x.image" :src="x.image" :alt="x.title" class="exp__img" loading="lazy" />
                <div class="min-w-0">
                  <p class="font-extrabold text-[var(--color-text-dark)]">{{ x.title }}</p>
                  <p class="text-[13px] font-semibold text-[var(--color-text-muted)]">฿{{ money(x.price_per_person) }}/คน<span v-if="x.duration_label"> · {{ x.duration_label }}</span></p>
                  <p v-if="x.description" class="mt-1 text-[13px] text-[var(--color-text-mid)] line-clamp-2">{{ x.description }}</p>
                </div>
                <span class="material-symbols-rounded exp__check">{{ chosenExperiences.includes(x.id) ? 'check_circle' : 'add_circle' }}</span>
              </label>
            </div>
          </section>

          <section v-if="stay.house_rules || stay.cancellation_policy">
            <h2 class="section-kicker">ก่อนจอง</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
              <div class="side-card">
                <p class="font-extrabold text-[var(--color-text-dark)]">เช็คอิน {{ stay.check_in_time }} · เช็คเอาต์ {{ stay.check_out_time }}</p>
                <p v-if="stay.house_rules" class="mt-2 whitespace-pre-line text-[14px] text-[var(--color-text-mid)]">{{ stay.house_rules }}</p>
                <p v-if="stay.license_label" class="mt-3 text-[12px] font-bold text-[var(--color-text-muted)]">
                  <span class="material-symbols-rounded icon-16 align-[-3px]">verified</span>
                  {{ stay.license_label }}<span v-if="stay.license_no"> เลขที่ {{ stay.license_no }}</span>
                </p>
              </div>
              <div class="side-card">
                <p class="font-extrabold text-[var(--color-text-dark)]">ยกเลิกแบบ{{ stay.cancellation_policy.label }}</p>
                <ul class="mt-2 space-y-1 text-[14px] text-[var(--color-text-mid)]">
                  <li v-for="line in stay.cancellation_policy.lines" :key="line">• {{ line }}</li>
                </ul>
              </div>
            </div>
          </section>

          <section v-if="stay.trips.length">
            <h2 class="section-kicker">ทริปใกล้ที่พักนี้</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
              <router-link v-for="trip in stay.trips" :key="trip.id" :to="`/trips/${trip.slug}`" class="flex items-center gap-3 rounded-2xl bg-white border border-[var(--color-sand-dark)] p-3 group">
                <img v-if="trip.cover_image" :src="trip.cover_image" :alt="trip.title" class="h-14 w-14 rounded-xl object-cover" loading="lazy" />
                <span class="min-w-0">
                  <span class="block truncate font-bold text-[var(--color-text-dark)] group-hover:text-[var(--color-accent)]">{{ trip.title }}</span>
                  <span v-if="trip.distance_note" class="block text-[13px] text-[var(--color-text-muted)]">{{ trip.distance_note }}</span>
                </span>
              </router-link>
            </div>
          </section>

          <section>
            <h2 class="section-kicker">รีวิวจากผู้เข้าพัก</h2>
            <p v-if="!stay.reviews.length" class="mt-3 text-sm text-[var(--color-text-muted)]">ยังไม่มีรีวิว — เป็นคนแรกที่ไปพักแล้วเล่าให้คนอื่นฟังได้เลย</p>
            <div v-else class="mt-4 grid gap-3">
              <article v-for="r in stay.reviews" :key="r.id" class="side-card">
                <p class="text-amber-500 font-black">{{ '★'.repeat(r.rating) }}<span class="text-[var(--color-sand-dark)]">{{ '★'.repeat(5 - r.rating) }}</span></p>
                <p v-if="r.comment" class="mt-1 text-[15px] text-[var(--color-text-dark)]">{{ r.comment }}</p>
                <p class="mt-1 text-[12px] font-bold text-[var(--color-text-muted)]">{{ r.author }}</p>
                <p v-if="r.host_reply" class="mt-2 rounded-xl bg-[var(--color-sand)] p-3 text-[13px] text-[var(--color-text-mid)]">
                  <strong>เจ้าของที่พัก:</strong> {{ r.host_reply }}
                </p>
              </article>
            </div>
          </section>
        </div>

        <!-- ── กล่องจอง ── -->
        <aside class="lg:sticky lg:top-24 self-start">
          <div class="book-card">
            <p class="text-lg font-black text-[var(--color-text-dark)]">จองที่พัก</p>
            <p class="text-[13px] font-semibold text-[var(--color-text-muted)]">
              {{ stay.booking_mode === 'instant' ? `ยืนยันห้องทันทีหลังชำระเงิน (ภายใน ${stay.payment_minutes} นาที)` : 'ส่งคำขอก่อน ที่พักยืนยันห้องว่างภายใน 24 ชม. แล้วค่อยจ่าย' }}
            </p>

            <div class="mt-4 grid grid-cols-2 gap-2">
              <label class="field"><span>เช็คอิน</span><input type="date" v-model="checkIn" :min="today" @change="onDates" /></label>
              <label class="field"><span>เช็คเอาต์</span><input type="date" v-model="checkOut" :min="minCheckOut" @change="onDates" /></label>
            </div>
            <label class="field mt-2"><span>จำนวนผู้เข้าพัก</span>
              <select v-model.number="guests" @change="onDates">
                <option v-for="n in 20" :key="n" :value="n">{{ n }} คน</option>
              </select>
            </label>

            <p v-if="availError" class="mt-3 text-sm font-bold text-red-600">{{ availError }}</p>
            <p v-else-if="!availability" class="mt-3 text-[13px] text-[var(--color-text-muted)]">เลือกวันเพื่อดูห้องว่างและราคาจริง</p>
            <p v-else-if="!picked" class="mt-3 text-[13px] font-bold text-[var(--color-accent)]">
              {{ freeRooms ? `มีห้องว่าง ${freeRooms} แบบ — เลือกห้องด้านซ้าย` : 'ไม่มีห้องว่างในวันที่เลือก ลองเปลี่ยนวันนะครับ' }}
            </p>

            <template v-if="picked">
              <div class="mt-4 rounded-2xl bg-[var(--color-sand)] p-3">
                <div class="flex items-center justify-between gap-2">
                  <p class="font-extrabold text-[var(--color-text-dark)]">{{ picked.name }}</p>
                  <label class="flex items-center gap-1 text-[13px] font-bold">
                    <select v-model.number="units" @change="refreshQuote" class="rounded-lg border border-[var(--color-sand-dark)] bg-white px-2 py-1">
                      <option v-for="n in unitOptions" :key="n" :value="n">{{ n }} ห้อง</option>
                    </select>
                  </label>
                </div>
                <div v-if="quote" class="mt-3 space-y-1 text-[14px]">
                  <div class="row"><span>ค่าห้อง {{ quote.nights }} คืน × {{ units }} ห้อง</span><span>฿{{ money(quote.room_amount) }}</span></div>
                  <div v-if="quote.extra_guest_amount" class="row"><span>คนเพิ่ม {{ quote.extra_guests }} คน</span><span>฿{{ money(quote.extra_guest_amount) }}</span></div>
                  <div v-for="x in quote.experiences" :key="x.id" class="row"><span>{{ x.title }} × {{ x.people }}</span><span>฿{{ money(x.amount) }}</span></div>
                  <div class="row total"><span>รวม</span><span>฿{{ money(quote.total_amount) }}</span></div>
                  <p class="text-[12px] font-bold text-[var(--color-accent)]">฿{{ money(quote.host_amount) }} ถึงมือเจ้าของที่พัก</p>
                </div>
                <p v-if="quoteError" class="mt-2 text-sm font-bold text-red-600">{{ quoteError }}</p>
              </div>

              <template v-if="auth.isLoggedIn">
                <label class="field mt-3"><span>ชื่อผู้เข้าพัก</span><input v-model="guestName" maxlength="150" /></label>
                <label class="field mt-2"><span>เบอร์โทร</span><input v-model="guestPhone" inputmode="tel" maxlength="30" /></label>
                <label class="field mt-2"><span>ถึงเจ้าของที่พัก (ไม่บังคับ)</span><textarea v-model="note" rows="2" maxlength="1000" placeholder="เช่น ถึงประมาณ 2 ทุ่ม / แพ้อาหารทะเล"></textarea></label>
                <label v-if="tripBookingRef" class="mt-2 flex items-center gap-2 text-[13px] font-bold text-[var(--color-text-mid)]">
                  <input type="checkbox" v-model="linkTrip" /> ผูกกับการจองทริป {{ tripBookingRef }}
                </label>
                <button type="button" class="book-btn press mt-4" :disabled="!quote || submitting || !guestName.trim() || !guestPhone.trim()" @click="submit">
                  {{ submitting ? 'กำลังจอง…' : stay.booking_mode === 'instant' ? 'จองและไปชำระเงิน' : 'ส่งคำขอจอง' }}
                </button>
              </template>
              <router-link v-else :to="{ name: 'login', query: { redirect: $route.fullPath } }" class="book-btn press mt-4 text-center">
                เข้าสู่ระบบเพื่อจอง
              </router-link>
            </template>
          </div>
        </aside>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../lib/axios';
import { useAuthStore } from '../stores/auth';
import { useToast } from '../lib/toast';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const toast = useToast();

const loading = ref(true);
const notFound = ref(false);
const stay = ref(null);

const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Bangkok' });
const checkIn = ref(String(route.query.check_in || ''));
const checkOut = ref(String(route.query.check_out || ''));
const guests = ref(Number(route.query.guests) || 2);
const availability = ref(null);
const availError = ref('');
const picked = ref(null);
const units = ref(1);
const chosenExperiences = ref([]);
const quote = ref(null);
const quoteError = ref('');
const guestName = ref(auth.user?.name || '');
const guestPhone = ref(auth.user?.phone || '');
const note = ref('');
const submitting = ref(false);
// มาจากหน้าใบจองทริป "จองที่พักคืนก่อน/หลังทริป"
const tripBookingRef = String(route.query.booking || '');
const linkTrip = ref(!!tripBookingRef);

const minCheckOut = computed(() => {
  if (!checkIn.value) return today;
  const d = new Date(checkIn.value + 'T00:00:00Z');
  d.setUTCDate(d.getUTCDate() + 1);
  return d.toISOString().slice(0, 10);
});

const roomRows = computed(() => {
  if (!stay.value) return [];
  if (!availability.value) return stay.value.room_types;
  return availability.value.rooms;
});

const freeRooms = computed(() => (availability.value?.rooms || []).filter(r => r.available_units >= r.units_needed).length);

const unitOptions = computed(() => {
  if (!picked.value) return [1];
  const max = Math.min(picked.value.available_units, 10);
  const min = picked.value.units_needed || 1;
  return Array.from({ length: Math.max(0, max - min + 1) }, (_, i) => min + i);
});

function money(n) {
  return Math.round(Number(n || 0)).toLocaleString('th-TH');
}

async function onDates() {
  picked.value = null;
  quote.value = null;
  availError.value = '';
  if (!checkIn.value || !checkOut.value) { availability.value = null; return; }
  if (checkOut.value <= checkIn.value) { availError.value = 'วันเช็คเอาต์ต้องอยู่หลังวันเช็คอิน'; availability.value = null; return; }
  try {
    const res = await api.get(`/stays/${stay.value.slug}/availability`, {
      params: { check_in: checkIn.value, check_out: checkOut.value, guests: guests.value },
    });
    availability.value = res.data.data;
  } catch (err) {
    availability.value = null;
    availError.value = err.response?.data?.message || 'ดูห้องว่างไม่สำเร็จ';
  }
}

function pick(room) {
  picked.value = room;
  units.value = room.units_needed || 1;
  refreshQuote();
}

async function refreshQuote() {
  if (!picked.value) return;
  quoteError.value = '';
  try {
    const res = await api.post(`/stays/${stay.value.slug}/quote`, bookingBody());
    quote.value = res.data.data;
  } catch (err) {
    quote.value = null;
    quoteError.value = err.response?.data?.message || 'คิดราคาไม่สำเร็จ';
  }
}

function bookingBody() {
  return {
    room_type_id: picked.value.id,
    check_in: checkIn.value,
    check_out: checkOut.value,
    units: units.value,
    guests: guests.value,
    experiences: chosenExperiences.value.map(id => ({ id })),
    guest_name: guestName.value.trim(),
    guest_phone: guestPhone.value.trim(),
    note: note.value.trim() || null,
    trip_booking_ref: linkTrip.value && tripBookingRef ? tripBookingRef : null,
  };
}

async function submit() {
  submitting.value = true;
  try {
    const res = await api.post(`/stays/${stay.value.slug}/bookings`, bookingBody());
    toast.success(res.data.message);
    router.push(`/stays/bookings/${res.data.data.ref}`);
  } catch (err) {
    toast.error(err.response?.data?.message || 'จองไม่สำเร็จ');
    // ห้องอาจเพิ่งเต็ม — โหลดห้องว่างใหม่
    if (err.response?.status === 422) onDates();
  } finally {
    submitting.value = false;
  }
}

async function load(slug) {
  loading.value = true;
  notFound.value = false;
  try {
    const res = await api.get(`/stays/${slug}`);
    stay.value = res.data.data;
    document.title = `${stay.value.name} | ที่พักชุมชน | ลุยเลเขา`;
    if (checkIn.value && checkOut.value) onDates();
  } catch {
    notFound.value = true;
  } finally {
    loading.value = false;
  }
}

watch(() => route.params.slug, (slug) => slug && load(slug), { immediate: true });
</script>

<style scoped>
.hero-tag { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.3rem 0.8rem; border-radius: 999px; background: rgba(255,255,255,.18); backdrop-filter: blur(6px); color: #fff; font-size: 13px; font-weight: 800; }
.hero-tag--accent { background: var(--color-accent); }
.section-kicker { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 15px; font-weight: 800; color: var(--color-text-dark); }
.section-kicker::before { content: ''; width: 1.5rem; height: 2px; border-radius: 2px; background-color: var(--color-accent); }
.side-card { padding: 1.25rem; border-radius: 1.25rem; border: 1px solid var(--color-sand-dark); background: #fff; }
.gallery { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.5rem; }
.gallery__item { display: block; aspect-ratio: 1; border-radius: 1rem; background-color: var(--color-sand-dark); }
.gallery__item:first-child { grid-column: span 2; grid-row: span 2; }
.gallery__item img { width: 100%; height: 100%; object-fit: cover; }
.community { position: relative; padding: 1.75rem; border-radius: 1.5rem; background: var(--color-accent-tint); }
.community__icon { font-size: 32px; color: var(--color-accent); }
.room { display: flex; gap: 1rem; padding: 1rem; border-radius: 1.25rem; background: #fff; border: 1px solid var(--color-sand-dark); }
.room--picked { border-color: var(--color-accent); box-shadow: inset 0 0 0 1px var(--color-accent); }
.room__img { width: 120px; height: 120px; border-radius: 1rem; object-fit: cover; flex-shrink: 0; }
.pick-btn { padding: 0.55rem 1.1rem; border-radius: 999px; background: var(--color-accent); color: #fff; font-size: 14px; font-weight: 800; }
.exp { position: relative; display: flex; gap: 0.75rem; padding: 0.9rem; padding-right: 2.5rem; border-radius: 1.25rem; background: #fff; border: 1px solid var(--color-sand-dark); cursor: pointer; }
.exp--on { border-color: var(--color-accent); box-shadow: inset 0 0 0 1px var(--color-accent); }
.exp__img { width: 64px; height: 64px; border-radius: 0.8rem; object-fit: cover; flex-shrink: 0; }
.exp__check { position: absolute; right: 0.8rem; top: 0.8rem; color: var(--color-accent); }
.book-card { padding: 1.25rem; border-radius: 1.5rem; background: #fff; border: 1px solid var(--color-sand-dark); }
.field { display: grid; gap: 0.25rem; }
.field > span { font-size: 12px; font-weight: 800; color: var(--color-text-muted); }
.field input, .field select, .field textarea { width: 100%; padding: 0.6rem 0.75rem; border-radius: 0.8rem; border: 1px solid var(--color-sand-dark); background: #fff; font-size: 15px; font-weight: 600; }
.row { display: flex; justify-content: space-between; gap: 0.75rem; color: var(--color-text-mid); }
.row.total { padding-top: 0.4rem; border-top: 1px dashed var(--color-sand-dark); font-size: 16px; font-weight: 900; color: var(--color-text-dark); }
.book-btn { display: block; width: 100%; padding: 0.85rem; border-radius: 999px; background: var(--color-accent); color: #fff; font-size: 15px; font-weight: 900; }
.book-btn:disabled { opacity: 0.45; }
@media (max-width: 640px) {
  .room { flex-direction: column; }
  .room__img { width: 100%; height: 180px; }
}
</style>
