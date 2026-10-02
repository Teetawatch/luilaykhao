<template>
  <div class="min-h-screen bg-[#F4F7F6] pt-8 pb-32 font-anuphan">
    <div class="max-w-lg mx-auto px-4 sm:px-6">

      <div v-if="loading" class="flex justify-center py-24">
        <div class="w-10 h-10 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
      </div>

      <!-- ลิงก์ไม่มีอยู่ -->
      <div v-else-if="notFound" class="bg-white rounded-[24px] border border-[#E8EEEF] p-8 text-center">
        <span class="material-symbols-rounded text-4xl text-[#E11D48]">link_off</span>
        <h1 class="text-xl font-black text-[#1a1c1c] mt-3 mb-2">ใช้ลิงก์นี้ไม่ได้</h1>
        <p class="text-sm text-[#505E5E] leading-relaxed mb-6">{{ notFound }}</p>
        <router-link to="/"
          class="inline-flex items-center gap-1.5 bg-[#006565] text-white px-5 py-2.5 rounded-full text-sm font-extrabold">
          <span class="material-symbols-rounded text-[18px]">home</span> กลับหน้าแรก
        </router-link>
      </div>

      <template v-else-if="preview">
        <section class="mb-6">
          <p class="text-xs font-bold text-[#006565] tracking-wider mb-1">ส่งต่อที่นั่ง</p>
          <h1 class="text-3xl font-bold text-[#1a1c1c] tracking-tight" style="font-family:'DB Heavent','Anuphan',sans-serif;">
            {{ preview.from_name }} ส่งที่นั่งให้คุณ
          </h1>
        </section>

        <!-- ทริปที่ได้รับ -->
        <div class="bg-white rounded-[24px] border border-[#E8EEEF] overflow-hidden mb-5">
          <img v-if="preview.trip?.cover_image" :src="preview.trip.cover_image" alt=""
            class="w-full h-40 object-cover" />
          <div class="p-6">
            <h2 class="text-lg font-black text-[#1a1c1c] leading-snug mb-3">{{ preview.trip?.title || 'ทริป' }}</h2>
            <div class="space-y-2 text-sm font-bold text-[#505E5E]">
              <p class="flex items-start gap-2">
                <span class="material-symbols-rounded text-[18px] text-[#006565]">event</span>
                <span>
                  {{ preview.schedule?.departure_label }}
                  <span v-if="preview.schedule?.early_departure_label" class="block text-[#B45309]">{{ preview.schedule.early_departure_label }}</span>
                </span>
              </p>
              <p v-if="preview.seat_label" class="flex items-center gap-2">
                <span class="material-symbols-rounded text-[18px] text-[#006565]">event_seat</span>
                ที่นั่ง {{ preview.seat_label }}
              </p>
              <p v-if="preview.pickup" class="flex items-start gap-2">
                <span class="material-symbols-rounded text-[18px] text-[#006565]">location_on</span>
                <span>
                  ขึ้นรถที่ {{ preview.pickup.label }}<template v-if="preview.pickup.time"> · {{ preview.pickup.time }} น.</template>
                  <a v-if="preview.pickup.map_url" :href="preview.pickup.map_url" target="_blank" rel="noopener"
                    class="block text-[#006565] underline font-semibold">ดูแผนที่</a>
                </span>
              </p>
            </div>

            <blockquote v-if="preview.note"
              class="mt-4 border-l-4 border-[#BCDFDF] pl-3 text-sm text-[#1a1c1c] italic">“{{ preview.note }}”</blockquote>

            <div class="mt-4 space-y-2 text-[12px] leading-relaxed">
              <p v-if="preview.transfers_ownership" class="rounded-[12px] bg-[#F0FAFA] text-[#0F3D3E] px-3 py-2">
                คุณจะได้ดูแลการจองนี้แทน{{ preview.from_name }}ด้วย (แชท วันเดินทาง และเรื่องการจองทั้งหมด)
              </p>
              <p v-if="preview.pending_share_amount" class="rounded-[12px] bg-[#FFFBEB] text-[#92400E] px-3 py-2">
                ที่นั่งนี้ยังมีส่วนแบ่งค่าทริปค้างจ่าย ฿{{ Number(preview.pending_share_amount).toLocaleString('th-TH') }}
                รับแล้วจะเป็นส่วนของคุณ
              </p>
              <p class="text-[#889696]">ค่าที่นั่งตกลงกันเองกับคนที่ส่งให้คุณ ทางเราไม่เก็บเงินเพิ่มจากการรับที่นั่ง</p>
            </div>
          </div>
        </div>

        <!-- รับไม่ได้ -->
        <div v-if="!preview.claimable" class="bg-white rounded-[20px] border border-[#FDE68A] p-5 text-center">
          <p class="text-sm font-bold text-[#B45309]">{{ preview.blocked_reason }}</p>
          <router-link v-if="preview.claimed_by_viewer" to="/my-bookings"
            class="inline-flex items-center gap-1.5 mt-4 bg-[#006565] text-white px-5 py-2.5 rounded-full text-sm font-extrabold">
            ไปที่การจองของฉัน
          </router-link>
        </div>

        <!-- ฟอร์มข้อมูลผู้เดินทาง -->
        <form v-else class="bg-white rounded-[24px] border border-[#E8EEEF] p-6 space-y-4" @submit.prevent="submit" novalidate>
          <div>
            <h2 class="text-lg font-black text-[#1a1c1c]">ข้อมูลของคุณ</h2>
            <p class="text-xs text-[#889696] mt-1">ใช้ทำประกันการเดินทางและให้ทีมงานติดต่อ — กรอกตามบัตรประชาชน</p>
          </div>

          <div v-if="isInternational">
            <label class="lbl">สัญชาติ</label>
            <select v-model="form.nationality" class="inp">
              <option v-for="c in countries" :key="c.code" :value="c.code">{{ c.flag }} {{ c.name }}</option>
            </select>
          </div>

          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="lbl">คำนำหน้า</label>
              <select v-model="form.title" class="inp" :class="{ err: errors.title }">
                <option value="" disabled>เลือก</option>
                <option v-for="t in titles" :key="t" :value="t">{{ t }}</option>
              </select>
            </div>
            <div class="col-span-2">
              <label class="lbl">ชื่อเล่น</label>
              <input v-model.trim="form.nickname" class="inp" :class="{ err: errors.nickname }" maxlength="100" />
            </div>
          </div>
          <p v-if="errors.title" class="msg">{{ errors.title }}</p>
          <p v-if="errors.nickname" class="msg">{{ errors.nickname }}</p>

          <div>
            <label class="lbl">ชื่อ-นามสกุล{{ isThai ? ' (ภาษาไทยตามบัตร)' : '' }}</label>
            <input v-model.trim="form.name" class="inp" :class="{ err: errors.name }" autocomplete="name" />
            <p v-if="errors.name" class="msg">{{ errors.name }}</p>
          </div>

          <div v-if="isThai">
            <label class="lbl">เลขบัตรประชาชน</label>
            <input v-model.trim="form.id_card" class="inp" :class="{ err: errors.id_card }" inputmode="numeric" maxlength="17" autocomplete="off" />
            <p v-if="errors.id_card" class="msg">{{ errors.id_card }}</p>
          </div>

          <template v-if="isInternational">
            <div>
              <label class="lbl">ชื่อ-สกุลภาษาอังกฤษ (ตามพาสปอร์ต)</label>
              <input v-model.trim="form.name_en" class="inp uppercase" :class="{ err: errors.name_en }" />
              <p v-if="errors.name_en" class="msg">{{ errors.name_en }}</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="lbl">เลขที่พาสปอร์ต</label>
                <input v-model.trim="form.passport_no" class="inp uppercase" :class="{ err: errors.passport_no }" />
              </div>
              <div>
                <label class="lbl">วันหมดอายุ</label>
                <input v-model="form.passport_expires_at" type="date" class="inp" :class="{ err: errors.passport_expires_at }" />
              </div>
            </div>
            <p v-if="errors.passport_no" class="msg">{{ errors.passport_no }}</p>
            <p v-if="errors.passport_expires_at" class="msg">{{ errors.passport_expires_at }}</p>
          </template>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="lbl">วันเกิด</label>
              <input v-model="form.birth_date" type="date" class="inp" :class="{ err: errors.birth_date }" />
            </div>
            <div>
              <label class="lbl">กรุ๊ปเลือด</label>
              <select v-model="form.blood_group" class="inp" :class="{ err: errors.blood_group }">
                <option value="" disabled>เลือก</option>
                <option v-for="g in ['A', 'B', 'O', 'AB']" :key="g" :value="g">{{ g }}</option>
              </select>
            </div>
          </div>
          <p v-if="errors.birth_date" class="msg">{{ errors.birth_date }}</p>
          <p v-if="errors.blood_group" class="msg">{{ errors.blood_group }}</p>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="lbl">เบอร์โทร</label>
              <input v-model.trim="form.phone" class="inp" :class="{ err: errors.phone }" inputmode="tel" autocomplete="tel" />
            </div>
            <div>
              <label class="lbl">อีเมล (ไม่บังคับ)</label>
              <input v-model.trim="form.email" type="email" class="inp" :class="{ err: errors.email }" autocomplete="email" />
            </div>
          </div>
          <p v-if="errors.phone" class="msg">{{ errors.phone }}</p>
          <p v-if="errors.email" class="msg">{{ errors.email }}</p>

          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="lbl">ผู้ติดต่อฉุกเฉิน</label>
              <input v-model.trim="form.emergency_contact" class="inp" :class="{ err: errors.emergency_contact }" placeholder="ชื่อ / ความสัมพันธ์" />
            </div>
            <div>
              <label class="lbl">เบอร์ฉุกเฉิน</label>
              <input v-model.trim="form.emergency_phone" class="inp" :class="{ err: errors.emergency_phone }" inputmode="tel" />
            </div>
          </div>
          <p v-if="errors.emergency_contact" class="msg">{{ errors.emergency_contact }}</p>
          <p v-if="errors.emergency_phone" class="msg">{{ errors.emergency_phone }}</p>

          <div>
            <label class="lbl">อาหารฮาลาล</label>
            <div class="flex gap-2">
              <button v-for="opt in [{ v: false, t: 'ไม่ต้องการ' }, { v: true, t: 'ต้องการ' }]" :key="opt.t" type="button"
                @click="form.halal_food = opt.v"
                class="flex-1 py-2.5 rounded-[12px] border text-sm font-bold"
                :class="form.halal_food === opt.v ? 'border-[#006565] bg-[#F0FAFA] text-[#006565]' : 'border-[#D5DEDE] text-[#505E5E]'">
                {{ opt.t }}
              </button>
            </div>
            <p v-if="errors.halal_food" class="msg">{{ errors.halal_food }}</p>
          </div>

          <div>
            <label class="lbl">แพ้อาหาร/ยา (ไม่บังคับ)</label>
            <input v-model.trim="form.allergies" class="inp" maxlength="1000" />
          </div>
          <div>
            <label class="lbl">โรคประจำตัว / ข้อมูลสุขภาพ (ไม่บังคับ)</label>
            <textarea v-model.trim="form.health_notes" class="inp" rows="2" maxlength="1000"></textarea>
          </div>

          <label class="flex items-start gap-3 text-[13px] text-[#1a1c1c]">
            <input type="checkbox" v-model="form.accept_terms" class="mt-0.5 w-4 h-4 accent-[#006565]" />
            <span>
              ฉันอ่านและยอมรับ
              <a :href="preview.terms?.url" target="_blank" rel="noopener" class="text-[#006565] underline font-bold">เงื่อนไขการเดินทาง</a>
              ของลุยเลเขา
            </span>
          </label>
          <p v-if="errors.accept_terms" class="msg">{{ errors.accept_terms }}</p>
          <p v-if="errors.terms_version" class="msg">{{ errors.terms_version }}</p>

          <p v-if="formError" class="text-sm text-[#DC2626] bg-[#FEF2F2] border border-[#FECACA] rounded-[12px] p-3">{{ formError }}</p>

          <button type="submit" :disabled="submitting || !form.accept_terms"
            class="w-full bg-[#006565] hover:bg-[#005252] disabled:opacity-60 text-white py-3.5 rounded-full font-extrabold text-sm flex items-center justify-center gap-2 transition">
            <span v-if="submitting" class="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin"></span>
            <span v-else class="material-symbols-rounded text-[20px]">how_to_reg</span>
            {{ submitting ? 'กำลังรับที่นั่ง...' : 'รับที่นั่งนี้' }}
          </button>
        </form>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useHead } from '@unhead/vue';
import api from '../lib/axios';
import { useToast } from '../lib/toast';

/**
 * ปลายทางของลิงก์ "ส่งต่อที่นั่ง" (url('/handover/{token}')) — คนรับกรอกข้อมูลของ
 * ตัวเองแล้วรับที่นั่ง กติกาทั้งหมดตรวจที่เซิร์ฟเวอร์ (ClaimSeatHandoverRequest)
 * หน้านี้แสดงข้อความผิดพลาดรายช่องตามที่เซิร์ฟเวอร์ตอบ จะได้ไม่ต้องลอกกติกามาไว้สองที่
 */
useHead({ title: 'รับที่นั่งต่อ — ลุยเลเขา' });

const route = useRoute();
const router = useRouter();
const toast = useToast();

const preview = ref(null);
const loading = ref(true);
const notFound = ref('');
const submitting = ref(false);
const formError = ref('');
const errors = reactive({});
const countries = ref([{ code: 'TH', name: 'ไทย', flag: '🇹🇭' }]);

const form = reactive({
  title: '', name: '', nickname: '', nationality: 'TH', id_card: '', birth_date: '',
  phone: '', email: '', blood_group: '', halal_food: null, allergies: '', health_notes: '',
  emergency_contact: '', emergency_phone: '', name_en: '', passport_no: '', passport_expires_at: '',
  accept_terms: false,
});

const isInternational = computed(() => !!preview.value?.trip?.is_international);
const isThai = computed(() => form.nationality === 'TH');
const titles = computed(() => (preview.value?.trip?.is_women_only
  ? ['นาง', 'นางสาว']
  : ['นาย', 'นาง', 'นางสาว']));

function fillFrom(prefill) {
  if (!prefill) return;
  for (const key of Object.keys(form)) {
    if (key === 'accept_terms' || key === 'halal_food') continue;
    const value = prefill[key];
    if (value !== null && value !== undefined && value !== '') form[key] = value;
  }
  if (!titles.value.includes(form.title)) form.title = '';
}

function clearErrors() {
  for (const key of Object.keys(errors)) delete errors[key];
  formError.value = '';
}

async function submit() {
  clearErrors();
  if (form.halal_food === null) {
    errors.halal_food = 'กรุณาระบุว่าทานอาหารฮาลาลหรือไม่';
    return;
  }

  submitting.value = true;
  try {
    const payload = {
      ...form,
      terms_version: preview.value?.terms?.version,
      channel: 'web',
    };
    if (!isInternational.value) {
      delete payload.name_en;
      delete payload.passport_no;
      delete payload.passport_expires_at;
    }
    await api.post(`/seat-handovers/${route.params.token}/claim`, payload);
    toast.success('รับที่นั่งเรียบร้อย ยินดีต้อนรับสู่ทริปครับ');
    router.push('/my-bookings');
  } catch (e) {
    const data = e?.response?.data;
    if (data?.errors) {
      for (const [key, messages] of Object.entries(data.errors)) {
        errors[key] = Array.isArray(messages) ? messages[0] : messages;
      }
      formError.value = 'กรุณาตรวจสอบข้อมูลที่ไฮไลต์ไว้';
    } else {
      formError.value = data?.message || 'รับที่นั่งไม่สำเร็จ กรุณาลองใหม่อีกครั้ง';
      // สถานะของลิงก์อาจเปลี่ยนไปแล้ว (มีคนรับก่อน/ถูกยกเลิก) — โหลดใหม่ให้เห็นความจริง
      if (e?.response?.status === 422) await load(false);
    }
  } finally {
    submitting.value = false;
  }
}

async function load(prefill = true) {
  try {
    const res = await api.get(`/seat-handovers/${route.params.token}`);
    preview.value = res.data.data;
    if (prefill) fillFrom(preview.value.prefill);
  } catch (e) {
    notFound.value = e?.response?.data?.message || 'ลิงก์นี้ใช้ไม่ได้แล้ว — ขอลิงก์ใหม่จากคนที่ส่งให้คุณได้เลย';
  } finally {
    loading.value = false;
  }
}

onMounted(async () => {
  await load();
  if (isInternational.value) {
    try {
      const res = await api.get('/countries');
      if (res.data?.data?.length) countries.value = res.data.data;
    } catch {
      // ใช้แค่ไทยไปก่อน
    }
  }
});
</script>

<style scoped>
.lbl { display: block; font-size: 0.875rem; font-weight: 700; color: #1a1c1c; margin-bottom: 0.375rem; }
.inp { width: 100%; border-radius: 12px; border: 1px solid #D5DEDE; padding: 0.625rem 0.75rem; font-size: 0.875rem; background: #fff; }
.inp:focus { border-color: #006565; outline: none; }
.inp.err { border-color: #F87171; }
.msg { font-size: 0.75rem; color: #DC2626; margin-top: -0.5rem; }
</style>
