<!--
  ข้อมูลทริปในห้องแชทบนเว็บ — กำหนดการ / จุดรับ / รถและทีมงาน

  แอปมีชีตคำถามด่วนนี้มาตั้งแต่แรก เว็บไม่มี ทั้งที่ข้อความกำหนดการที่ระบบส่ง
  เข้าห้องบอกให้ "กดปุ่มกำหนดการเหนือช่องพิมพ์" — สำหรับคนที่เปิดจากเว็บมันคือ
  ทางตัน หน้านี้จึงยกทางลัดชุดเดียวกันมาไว้บนเว็บ โดยใช้ API ตัวเดียวกับแอป
-->
<template>
  <div class="fixed inset-0 z-50 flex items-end justify-center" @click.self="$emit('close')">
    <div class="absolute inset-0 bg-black/40"></div>

    <div class="relative w-full max-w-lg bg-white rounded-t-[24px] max-h-[85dvh] flex flex-col shadow-xl">
      <!-- Handle + tabs -->
      <div class="shrink-0 px-4 pt-3 pb-2 border-b border-[#E8EEEF]">
        <div class="w-10 h-1 rounded-full bg-[#E8EEEF] mx-auto mb-3"></div>
        <div class="flex items-center gap-2">
          <button
            v-for="t in tabs"
            :key="t.key"
            @click="active = t.key"
            class="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-[12px] text-[13px] font-bold transition-all"
            :class="active === t.key ? 'bg-[#006565] text-white' : 'bg-[#F4F7F6] text-[#505E5E] hover:bg-[#E8EEEF]'"
            style="font-family:'DB Heavent', 'Anuphan',sans-serif;"
          >
            <span class="material-symbols-rounded ti-icon ti-icon-tab">{{ t.icon }}</span>
            {{ t.label }}
          </button>
          <button
            @click="$emit('close')"
            class="w-9 h-9 shrink-0 rounded-[10px] border border-[#E8EEEF] flex items-center justify-center hover:bg-[#F4F7F6]"
            aria-label="ปิด"
          >
            <span class="material-symbols-rounded ti-icon ti-icon-close text-[#505E5E]">close</span>
          </button>
        </div>
      </div>

      <div class="flex-1 overflow-y-auto px-4 py-4" style="font-family:'DB Heavent', 'Anuphan',sans-serif;">
        <div v-if="loading" class="flex justify-center py-10">
          <div class="w-7 h-7 border-[3px] border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
        </div>

        <p v-else-if="errorMsg" class="text-sm text-[#505E5E] text-center py-10">{{ errorMsg }}</p>

        <!-- ── กำหนดการ ───────────────────────────────────────────────── -->
        <template v-else-if="active === 'itinerary'">
          <p v-if="itineraryItems.length === 0" class="text-sm text-[#505E5E] text-center py-10">
            ทีมงานจะลงกำหนดการให้ก่อนเดินทางครับ
          </p>

          <template v-else>
            <!-- หมายเหตุเดียวกับหน้ากำหนดการในแอป: แผนนี้เป็นของทริป ไม่ใช่ของรอบ -->
            <div v-if="itinerarySource === 'trip'" class="flex gap-2.5 p-3.5 rounded-[14px] bg-[#F4F7F6] mb-4">
              <span class="material-symbols-rounded ti-icon ti-icon-note text-[#889696] shrink-0">map</span>
              <p class="text-[13px] leading-relaxed text-[#505E5E]">
                นี่คือแผนการเดินทางของทริปนี้ ทีมงานยังไม่ได้ลงกำหนดการเฉพาะรอบ
                เวลาจริงหน้างานอาจขยับได้ตามสภาพอากาศและการจราจร
              </p>
            </div>

            <div v-for="(group, gi) in itineraryGroups" :key="gi" :class="gi > 0 ? 'mt-5' : ''">
              <div class="flex items-baseline gap-2 mb-2.5">
                <h3 class="text-[13px] font-bold text-[#1a1c1c]">{{ group.label }}</h3>
                <span v-if="group.date" class="text-[11px] text-[#889696]">{{ group.date }}</span>
              </div>

              <div class="space-y-2.5">
                <div v-for="(item, ii) in group.items" :key="ii" class="flex gap-3">
                  <span
                    class="shrink-0 w-[52px] pt-0.5 text-[12px] font-bold tabular-nums"
                    :class="item.time ? 'text-[#006565]' : 'text-[#C4CFCF]'"
                  >{{ item.time || '—' }}</span>
                  <div class="flex-1 min-w-0 pb-2.5 border-b border-[#F0F4F4]">
                    <p class="text-[13.5px] font-medium text-[#1a1c1c] leading-relaxed">{{ item.title }}</p>
                    <p v-if="item.detail" class="text-[12.5px] text-[#889696] leading-relaxed mt-0.5 whitespace-pre-line">{{ item.detail }}</p>
                  </div>
                </div>
              </div>
            </div>
          </template>
        </template>

        <!-- ── จุดรับ ─────────────────────────────────────────────────── -->
        <template v-else-if="active === 'pickup'">
          <div v-if="info.pickup" class="p-4 rounded-[16px] border border-[#006565]/25 bg-[#006565]/[0.04] mb-4">
            <p class="text-[11px] font-bold text-[#006565] mb-1">จุดรับของคุณ</p>
            <p class="text-[15px] font-bold text-[#1a1c1c] leading-snug">{{ info.pickup.location }}</p>
            <p v-if="info.pickup.time" class="text-[13px] text-[#505E5E] mt-1">
              พร้อมกันเวลา {{ info.pickup.time }} น.
            </p>
            <p v-if="info.pickup.notes" class="text-[12.5px] text-[#889696] mt-1.5 leading-relaxed whitespace-pre-line">{{ info.pickup.notes }}</p>
            <a
              v-if="info.pickup.map_url"
              :href="info.pickup.map_url"
              target="_blank"
              rel="noopener"
              class="inline-flex items-center gap-1.5 mt-3 px-3.5 py-2 rounded-full bg-[#006565] text-white text-[12.5px] font-bold"
            >
              <span class="material-symbols-rounded ti-icon ti-icon-map">location_on</span> เปิดแผนที่
            </a>
          </div>

          <p v-else class="text-sm text-[#505E5E] mb-4">{{ pendingCopy('pickup') }}</p>

          <template v-if="otherPickupPoints.length > 0">
            <h3 class="text-[13px] font-bold text-[#1a1c1c] mb-2">จุดรับอื่นของรอบนี้</h3>
            <div class="space-y-2">
              <div v-for="(p, i) in otherPickupPoints" :key="i" class="p-3 rounded-[12px] bg-[#F4F7F6]">
                <p class="text-[13.5px] font-medium text-[#1a1c1c]">{{ p.location }}</p>
                <p v-if="p.time" class="text-[12px] text-[#889696] mt-0.5">{{ p.time }} น.</p>
              </div>
            </div>
          </template>
        </template>

        <!-- ── รถและทีมงาน ─────────────────────────────────────────────── -->
        <template v-else>
          <h3 class="text-[13px] font-bold text-[#1a1c1c] mb-2">รถ</h3>
          <div v-if="info.vehicle" class="p-3.5 rounded-[14px] bg-[#F4F7F6] mb-5">
            <p class="text-[14px] font-bold text-[#1a1c1c]">{{ info.vehicle.name }}</p>
            <p class="text-[12.5px] text-[#505E5E] mt-0.5">
              <template v-if="info.vehicle.license_plate">ทะเบียน {{ info.vehicle.license_plate }}</template>
              <template v-if="info.vehicle.license_plate && info.vehicle.color"> · </template>
              <template v-if="info.vehicle.color">สี{{ info.vehicle.color }}</template>
            </p>
          </div>
          <p v-else class="text-[13px] text-[#889696] mb-5">{{ pendingCopy('vehicle') }}</p>

          <h3 class="text-[13px] font-bold text-[#1a1c1c] mb-2">คนขับ</h3>
          <div v-if="info.driver" class="mb-5">
            <PersonRow :name="info.driver.name" :phone="info.driver.phone" :photo="info.driver.photo" />
          </div>
          <p v-else class="text-[13px] text-[#889696] mb-5">{{ pendingCopy('driver') }}</p>

          <h3 class="text-[13px] font-bold text-[#1a1c1c] mb-2">ทีมงานประจำรอบ</h3>
          <div v-if="info.staff && info.staff.length > 0" class="space-y-2">
            <PersonRow
              v-for="s in info.staff"
              :key="s.id"
              :name="s.name"
              :phone="s.phone"
              :photo="s.avatar_url"
            />
          </div>
          <p v-else class="text-[13px] text-[#889696]">{{ pendingCopy('staff') }}</p>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import api from '../lib/axios';
import PersonRow from './TripInfoPersonRow.vue';

const props = defineProps({
  scheduleId: { type: [String, Number], required: true },
  initialTab: { type: String, default: 'itinerary' },
});

defineEmits(['close']);

const tabs = [
  { key: 'itinerary', label: 'กำหนดการ', icon: 'route' },
  { key: 'pickup', label: 'จุดรับ', icon: 'pin_drop' },
  { key: 'crew', label: 'รถและทีมงาน', icon: 'directions_bus' },
];

const active = ref(props.initialTab);
const loading = ref(true);
const errorMsg = ref('');
const info = ref({});
const itineraryItems = ref([]);
const itinerarySource = ref('schedule');

watch(() => props.initialTab, (v) => { active.value = v; });

/** ข้อความ "ยังไม่รู้" มาจาก backend เพื่อให้เว็บกับแอปพูดเหมือนกันเสมอ */
function pendingCopy(key) {
  return info.value?.pending?.[key] || 'ทีมงานจะยืนยันให้อีกครั้งก่อนเดินทางครับ';
}

/** จุดรับอื่นที่ไม่ใช่ของเรา — ช่วยให้เห็นภาพว่ารถวิ่งรับใครที่ไหนบ้าง */
const otherPickupPoints = computed(() => {
  const mine = info.value?.pickup?.location;
  return (info.value?.pickup_points || []).filter((p) => p.location !== mine);
});

/** จัดกลุ่มกำหนดการแบบเดียวกับข้อความในห้องและหน้ากำหนดการในแอป */
const itineraryGroups = computed(() => {
  const out = [];
  for (const item of itineraryItems.value) {
    const label = (item.group || '').trim() || 'แผนการเดินทาง';
    let group = out[out.length - 1];
    if (!group || group.label !== label) {
      group = { label, date: thaiDate(item.item_date), items: [] };
      out.push(group);
    }
    group.items.push(item);
  }
  return out;
});

const THAI_MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

/** "2026-09-12" → "12 ก.ย. 2569" — แยกสตริงเอง ไม่ผ่าน Date เพื่อกันเพี้ยนข้ามโซนเวลา */
function thaiDate(iso) {
  if (!iso) return '';
  const [y, m, d] = String(iso).slice(0, 10).split('-').map(Number);
  if (!y || !m || !d) return '';
  return `${d} ${THAI_MONTHS[m - 1]} ${y + 543}`;
}

async function load() {
  loading.value = true;
  errorMsg.value = '';
  try {
    // trip-info ให้จุดรับ/รถ/ทีมงาน ส่วน itinerary ดึงเต็มไม่ถูกตัดเหมือนตัวย่อในนั้น
    const [infoRes, itinRes] = await Promise.all([
      api.get(`/schedules/${props.scheduleId}/chat/trip-info`),
      api.get(`/schedules/${props.scheduleId}/itinerary`).catch(() => null),
    ]);

    info.value = infoRes.data.data || {};

    if (itinRes) {
      itineraryItems.value = itinRes.data.data?.items || [];
      itinerarySource.value = itinRes.data.data?.source || 'schedule';
    } else {
      itineraryItems.value = info.value.itinerary?.items || [];
      itinerarySource.value = info.value.itinerary?.source || 'schedule';
    }
  } catch (e) {
    errorMsg.value = e?.response?.data?.message || 'โหลดข้อมูลทริปไม่สำเร็จ กรุณาลองใหม่';
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
/* ดู TripChatPage.vue — ขนาดไอคอนต้องชนะกฎ 24px ที่อยู่นอก cascade layer ใน app.css */
.material-symbols-rounded.ti-icon-tab { font-size: 17px; }
.material-symbols-rounded.ti-icon-close { font-size: 18px; }
.material-symbols-rounded.ti-icon-note { font-size: 18px; }
.material-symbols-rounded.ti-icon-map { font-size: 16px; }
</style>
