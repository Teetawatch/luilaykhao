<template>
  <div class="min-h-screen bg-[#0F3D3E] pt-8 pb-24">
    <div class="max-w-lg mx-auto px-4 sm:px-6">

      <div v-if="loading" class="flex flex-col items-center justify-center py-24 space-y-4">
        <div class="w-10 h-10 border-4 border-white/20 border-t-white rounded-full animate-spin"></div>
        <p class="text-white/70 font-medium animate-pulse text-sm">กำลังรวบรวมทริปของคุณ...</p>
      </div>

      <div v-else-if="error" class="bg-white rounded-[20px] p-8 text-center">
        <span class="material-symbols-rounded text-[44px] text-[#B4C4C4]">sentiment_dissatisfied</span>
        <p class="text-[#1a1c1c] font-bold mt-3 mb-1">ยังดูสรุปทริปนี้ไม่ได้</p>
        <p class="text-[#505E5E] text-sm mb-5">{{ error }}</p>
        <router-link to="/my-bookings" class="inline-flex rounded-[14px] bg-[#006565] text-white text-sm font-bold px-5 py-3">
          กลับไปการจองของฉัน
        </router-link>
      </div>

      <template v-else-if="recap">
        <!-- ยังไม่จบทริป -->
        <div v-if="!recap.trip_completed" class="bg-white rounded-[20px] p-8 text-center">
          <span class="material-symbols-rounded text-[44px] text-[#B4C4C4]">hourglass_top</span>
          <p class="text-[#1a1c1c] font-bold mt-3 mb-1">ทริปนี้ยังไม่จบ</p>
          <p class="text-[#505E5E] text-sm mb-5">
            สรุปการเดินทางจะขึ้นให้ดูหลังกลับจาก {{ recap.trip.title }} แล้ว
          </p>
          <router-link to="/my-bookings" class="inline-flex rounded-[14px] bg-[#006565] text-white text-sm font-bold px-5 py-3">
            กลับไปการจองของฉัน
          </router-link>
        </div>

        <template v-else>
          <!-- ปก -->
          <section class="text-center mb-8 pt-4">
            <p class="text-white/60 text-[13px] font-bold tracking-wide mb-2">สรุปการเดินทาง</p>
            <h1 class="text-3xl font-extrabold text-white leading-tight mb-2">{{ recap.trip.title }}</h1>
            <p class="text-white/70 text-sm">{{ dateRange }}</p>
          </section>

          <!-- ตัวเลขทีละสไลด์ -->
          <section class="space-y-3 mb-6">
            <div
              v-for="stat in statSlides"
              :key="stat.key"
              class="rounded-[20px] bg-white/[0.07] border border-white/10 p-6"
            >
              <p class="text-white/60 text-[13px] font-bold mb-1">{{ stat.caption }}</p>
              <p class="text-4xl font-extrabold text-white tracking-tight">
                {{ stat.value }}<span class="text-lg font-bold text-white/60 ml-1.5">{{ stat.unit }}</span>
              </p>
              <p v-if="stat.note" class="text-white/60 text-[13px] mt-2 leading-relaxed">{{ stat.note }}</p>
            </div>
          </section>

          <!-- รูปจากรอบนี้ -->
          <section v-if="recap.photos?.length" class="mb-6">
            <p class="text-white/60 text-[13px] font-bold mb-3">ภาพจากทริปนี้</p>
            <div class="grid grid-cols-3 gap-1.5">
              <img
                v-for="(photo, i) in recap.photos"
                :key="i"
                :src="photo"
                alt=""
                loading="lazy"
                class="w-full aspect-square object-cover rounded-[12px]"
              />
            </div>
          </section>

          <!-- การ์ดแชร์ -->
          <section class="rounded-[20px] bg-white/[0.07] border border-white/10 p-6 mb-6">
            <p class="text-white font-bold mb-1">เก็บไว้เป็นภาพ</p>
            <p class="text-white/60 text-[13px] mb-4 leading-relaxed">
              บันทึกการ์ดสรุปทริปเป็นรูป เอาไปลงสตอรี่หรือส่งให้เพื่อนร่วมทริปได้
            </p>
            <div class="flex gap-2.5">
              <button
                type="button"
                :disabled="rendering"
                class="flex-1 rounded-[14px] bg-white text-[#0F3D3E] text-sm font-bold py-3 disabled:opacity-50"
                @click="downloadCard"
              >
                {{ rendering ? 'กำลังสร้าง...' : 'บันทึกรูป' }}
              </button>
              <button
                v-if="canShare"
                type="button"
                :disabled="rendering"
                class="flex-1 rounded-[14px] border border-white/25 text-white text-sm font-bold py-3 disabled:opacity-50"
                @click="shareCard"
              >
                แชร์
              </button>
            </div>
          </section>

          <!-- ชวนรีวิว -->
          <section v-if="!recap.has_reviewed" class="rounded-[20px] bg-white/[0.07] border border-white/10 p-6 mb-6">
            <p class="text-white font-bold mb-1">เล่าให้คนที่กำลังลังเลฟังหน่อย</p>
            <p class="text-white/60 text-[13px] mb-4 leading-relaxed">
              รีวิวของคุณคือสิ่งที่คนกำลังตัดสินใจอ่านก่อนเป็นอันดับแรก
            </p>
            <router-link
              to="/my-reviews"
              class="inline-flex rounded-[14px] bg-white text-[#0F3D3E] text-sm font-bold px-5 py-3"
            >
              เขียนรีวิว
            </router-link>
          </section>

          <div class="flex flex-wrap justify-center gap-x-5 gap-y-2 text-[13px] font-bold">
            <router-link to="/passport" class="text-white/70">ดูสมุดสะสมการเดินทาง</router-link>
            <router-link :to="`/trips/${recap.trip.slug}`" class="text-white/70">หน้าทริปนี้</router-link>
          </div>
        </template>
      </template>
    </div>

    <canvas ref="canvasEl" class="hidden"></canvas>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import api from '../lib/axios';
import { useToast } from '../lib/toast';
import { thaiShort } from '../lib/thaiDate';

const route = useRoute();
const toast = useToast();

const loading = ref(true);
const error = ref('');
const recap = ref(null);
const rendering = ref(false);
const canvasEl = ref(null);

const canShare = computed(() => typeof navigator !== 'undefined' && !!navigator.canShare);

const dateRange = computed(() => {
  if (!recap.value?.departure_date) return '';
  const start = thaiShort(recap.value.departure_date);
  const end = recap.value.return_date;
  return !end || end === recap.value.departure_date ? start : `${start} – ${thaiShort(end)}`;
});

/** สไลด์ตัวเลข — ข้ามอันที่ไม่มีข้อมูล ดีกว่าโชว์ 0 ให้ดูเหมือนไม่ได้ไปไหน */
const statSlides = computed(() => {
  const data = recap.value;
  if (!data) return [];

  const slides = [];

  if (data.duration_days) {
    slides.push({
      key: 'days',
      caption: 'คุณอยู่กับธรรมชาติ',
      value: data.duration_days,
      unit: 'วัน',
    });
  }

  if (data.distance_km) {
    slides.push({
      key: 'distance',
      caption: 'ระยะทางบนเส้นทางนี้',
      value: Number(data.distance_km).toLocaleString('th-TH'),
      unit: 'กม.',
    });
  }

  if (data.elevation_gain_m) {
    slides.push({
      key: 'elevation',
      caption: 'ความสูงที่ไต่ขึ้นไป',
      value: Number(data.elevation_gain_m).toLocaleString('th-TH'),
      unit: 'เมตร',
      note: `สูงกว่าตึกใบหยก 2 ราว ${Math.max(1, Math.round(data.elevation_gain_m / 304))} เท่า`,
    });
  }

  slides.push({
    key: 'group',
    caption: 'คุณเดินทางไปกับ',
    value: data.total_travelers || data.group_size,
    unit: 'คน',
    note: data.group_size > 1 ? `รวมคนที่คุณชวนไปเอง ${data.group_size} คน` : null,
  });

  if (data.trip.difficulty_label) {
    slides.push({
      key: 'difficulty',
      caption: 'ระดับที่คุณผ่านมาได้',
      value: data.trip.difficulty_label,
      unit: '',
    });
  }

  return slides;
});

/**
 * วาดการ์ดสรุปเป็น PNG ฝั่งเบราว์เซอร์ — ไม่ต้องมี endpoint สร้างรูปเพิ่ม และ
 * ได้ฟอนต์ไทยจากเครื่องผู้ใช้เองอยู่แล้ว
 */
async function renderCard() {
  const canvas = canvasEl.value;
  const data = recap.value;
  if (!canvas || !data) return null;

  const W = 1080;
  const H = 1350;
  canvas.width = W;
  canvas.height = H;

  const ctx = canvas.getContext('2d');
  const font = "'Anuphan', 'Noto Sans Thai', sans-serif";

  ctx.fillStyle = '#0F3D3E';
  ctx.fillRect(0, 0, W, H);

  ctx.textAlign = 'center';

  ctx.fillStyle = 'rgba(255,255,255,0.55)';
  ctx.font = `600 34px ${font}`;
  ctx.fillText('สรุปการเดินทาง', W / 2, 170);

  ctx.fillStyle = '#ffffff';
  ctx.font = `800 76px ${font}`;
  wrapText(ctx, data.trip.title, W / 2, 270, W - 160, 92);

  ctx.fillStyle = 'rgba(255,255,255,0.6)';
  ctx.font = `500 36px ${font}`;
  ctx.fillText(dateRange.value, W / 2, 430);

  // ตัวเลขหลักสามช่อง
  const cells = [
    [data.duration_days, 'วัน'],
    [data.distance_km ? Number(data.distance_km).toLocaleString('th-TH') : null, 'กม.'],
    [data.elevation_gain_m ? Number(data.elevation_gain_m).toLocaleString('th-TH') : null, 'ม. ที่ไต่'],
  ].filter(([value]) => value);

  if (cells.length) {
    const slotWidth = W / cells.length;
    cells.forEach(([value, unit], i) => {
      const x = slotWidth * i + slotWidth / 2;
      ctx.fillStyle = '#ffffff';
      ctx.font = `800 88px ${font}`;
      ctx.fillText(String(value), x, 660);
      ctx.fillStyle = 'rgba(255,255,255,0.55)';
      ctx.font = `600 32px ${font}`;
      ctx.fillText(unit, x, 712);
    });
  }

  ctx.fillStyle = 'rgba(255,255,255,0.12)';
  ctx.fillRect(120, 800, W - 240, 2);

  ctx.fillStyle = 'rgba(255,255,255,0.75)';
  ctx.font = `600 38px ${font}`;
  ctx.fillText(`เดินทางไปกับ ${data.total_travelers || data.group_size} คน`, W / 2, 890);

  if (data.trip.location) {
    ctx.fillStyle = 'rgba(255,255,255,0.5)';
    ctx.font = `500 34px ${font}`;
    ctx.fillText(data.trip.location, W / 2, 950);
  }

  ctx.fillStyle = 'rgba(255,255,255,0.4)';
  ctx.font = `700 30px ${font}`;
  ctx.fillText('luilaykhao.com', W / 2, H - 90);

  return new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
}

/** ตัดบรรทัดเองเพราะ canvas ไม่มี word-wrap ให้ */
function wrapText(ctx, text, x, y, maxWidth, lineHeight) {
  const words = String(text).split(' ');
  let line = '';
  let offsetY = y;

  words.forEach((word) => {
    const candidate = line ? `${line} ${word}` : word;
    if (ctx.measureText(candidate).width > maxWidth && line) {
      ctx.fillText(line, x, offsetY);
      line = word;
      offsetY += lineHeight;
    } else {
      line = candidate;
    }
  });

  if (line) ctx.fillText(line, x, offsetY);
}

async function downloadCard() {
  rendering.value = true;
  try {
    const blob = await renderCard();
    if (!blob) return;

    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `recap-${recap.value.booking_ref}.png`;
    link.click();
    URL.revokeObjectURL(url);
  } catch {
    toast.error('สร้างรูปไม่สำเร็จ');
  } finally {
    rendering.value = false;
  }
}

async function shareCard() {
  rendering.value = true;
  try {
    const blob = await renderCard();
    if (!blob) return;

    const file = new File([blob], `recap-${recap.value.booking_ref}.png`, { type: 'image/png' });

    if (navigator.canShare?.({ files: [file] })) {
      await navigator.share({ files: [file], title: recap.value.trip.title });
    } else {
      await downloadCard();
    }
  } catch (err) {
    // ผู้ใช้กดยกเลิกแผงแชร์เองก็เข้าทางนี้ ไม่ต้องขึ้น error ให้ตกใจ
    if (err?.name !== 'AbortError') toast.error('แชร์ไม่สำเร็จ');
  } finally {
    rendering.value = false;
  }
}

onMounted(async () => {
  try {
    const res = await api.get(`/bookings/${route.params.ref}/recap`);
    recap.value = res.data?.data;
  } catch (err) {
    error.value = err.response?.data?.message || 'โหลดสรุปทริปไม่สำเร็จ';
  } finally {
    loading.value = false;
  }
});
</script>
