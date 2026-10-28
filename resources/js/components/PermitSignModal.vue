<template>
  <Teleport to="body">
    <div class="fixed inset-0 bg-black/50 flex items-end sm:items-center justify-center z-50 sm:px-4" @click.self="close">
      <div class="bg-white w-full sm:max-w-2xl rounded-t-[24px] sm:rounded-[24px] overflow-hidden flex flex-col max-h-[94vh]">
        <!-- Header -->
        <div class="px-5 py-4 border-b border-[#E8EEEF] flex items-start justify-between gap-3 shrink-0">
          <div class="min-w-0 flex items-start gap-2">
            <button v-if="current" @click="current = null"
              class="w-8 h-8 rounded-lg border border-[#E8EEEF] hover:bg-[#F4F7F6] inline-flex items-center justify-center shrink-0"
              aria-label="กลับ">
              <span class="material-symbols-rounded text-[18px]">arrow_back</span>
            </button>
            <div class="min-w-0">
              <h3 class="text-lg font-bold text-[#1a1c1c]">{{ current ? `เซ็นแบบฟอร์ม · ${current.passenger.name}` : 'เซ็นแบบฟอร์มอุทยาน' }}</h3>
              <p class="text-xs text-[#505E5E] mt-0.5 truncate">
                {{ booking.schedule?.trip?.title || 'ทริป' }} · {{ booking.booking_ref }}
              </p>
            </div>
          </div>
          <button @click="close"
            class="w-8 h-8 rounded-lg border border-[#E8EEEF] hover:bg-[#F4F7F6] inline-flex items-center justify-center shrink-0"
            aria-label="ปิด">
            <span class="material-symbols-rounded text-[18px]">close</span>
          </button>
        </div>

        <div class="p-5 space-y-4 overflow-y-auto">
          <div v-if="loading" class="flex justify-center py-12">
            <div class="w-8 h-8 border-4 border-[#006565]/20 border-t-[#006565] rounded-full animate-spin"></div>
          </div>

          <p v-else-if="loadError" class="text-sm text-[#DC2626] bg-[#FEF2F2] border border-[#FECACA] rounded-[12px] p-3">{{ loadError }}</p>

          <!-- ─── รายชื่อ ─── -->
          <template v-else-if="!current && summary">
            <div class="rounded-[16px] bg-[#F0FAFA] border border-[#BCDFDF] p-4 text-[13px] text-[#0F3D3E] space-y-1.5">
              <p class="font-bold text-sm">อุทยานให้ผู้เดินทางทุกคนเซ็นแบบฟอร์มก่อนลงทะเบียน</p>
              <p>เราเติมข้อมูลที่ให้ไว้ตอนจองลงแบบฟอร์มแล้ว อ่านให้ครบแล้วเซ็นชื่อ ทีมงานจะนำไปลงทะเบียนให้</p>
              <p v-if="summary.deadline_label" class="font-bold" :class="summary.overdue ? 'text-[#B91C1C]' : ''">
                {{ summary.overdue ? 'เลยกำหนดแล้ว' : 'เซ็นภายใน' }} {{ summary.deadline_label }}
              </p>
            </div>

            <p class="text-sm font-bold text-[#1a1c1c]">เซ็นแล้ว {{ summary.signed_count }} จาก {{ summary.total }} คน</p>

            <div v-for="p in summary.passengers" :key="p.id" class="rounded-[16px] border border-[#E8EEEF] p-4">
              <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                  <p class="text-sm font-bold text-[#1a1c1c] truncate">{{ p.name }}</p>
                  <p class="text-xs mt-0.5" :class="p.signed ? 'text-[#047857]' : 'text-[#B45309]'">
                    {{ p.signed ? 'เซ็นแล้ว' : 'ยังไม่เซ็น' }}
                  </p>
                </div>
                <button @click="openPassenger(p)"
                  class="shrink-0 py-2 px-4 rounded-[12px] font-bold text-sm transition-all"
                  :class="p.signed
                    ? 'bg-white text-[#006565] border border-[#BCDFDF] hover:bg-[#F0FAFA]'
                    : 'bg-[#006565] text-white hover:bg-[#004D4D]'">
                  {{ p.signed ? 'ดูแบบฟอร์ม' : 'อ่านและเซ็น' }}
                </button>
              </div>
              <button v-if="p.sign_url && !p.signed" @click="copyLink(p)"
                class="mt-2 text-xs text-[#006565] font-bold inline-flex items-center gap-1 hover:underline">
                <span class="material-symbols-rounded text-[16px]">link</span>
                ก๊อปลิงก์ให้ {{ p.name }} เซ็นเอง
              </button>
            </div>
            <p v-if="summary.viewer_role === 'owner' && summary.total > 1" class="text-xs text-[#889696]">
              ให้เจ้าตัวเป็นคนเซ็นนะครับ — ส่งลิงก์ให้เพื่อนทางไลน์ได้ เปิดได้โดยไม่ต้องสมัครสมาชิก
            </p>
          </template>

          <!-- ─── แบบฟอร์มของคนหนึ่ง ─── -->
          <template v-else-if="current">
            <p class="text-[13px] text-[#505E5E]">อ่านข้อความในแบบฟอร์มให้ครบ — ช่องที่ยังว่าง ทีมงานจะกรอกให้หรือสอบถามเพิ่มเติม</p>

            <div v-for="(page, pi) in current.pages" :key="pi" ref="pageEls"
              class="relative w-full border border-[#E8EEEF] rounded-[8px] overflow-hidden bg-white">
              <img :src="page.url" alt="" class="block w-full h-auto" @load="measure" />
              <div v-for="(f, fi) in current.fields.filter((x) => x.page === pi)" :key="fi"
                class="absolute whitespace-nowrap"
                :class="f.type === 'signature' ? 'pf-sig' : 'pf-text'"
                :style="fieldStyle(f, page, pi)">
                <img v-if="f.type === 'signature' && current.signature_url" :src="current.signature_url" alt="ลายเซ็น"
                  class="pf-sig-img" :class="`pf-align-${f.align}`" />
                <template v-else>{{ f.value }}</template>
              </div>
            </div>

            <button @click="openPdf" :disabled="pdfLoading"
              class="text-sm text-[#006565] font-bold inline-flex items-center gap-1 hover:underline">
              <span class="material-symbols-rounded text-[18px]">picture_as_pdf</span>
              {{ pdfLoading ? 'กำลังเตรียม…' : 'เปิดเป็น PDF' }}
            </button>

            <div v-if="current.signed && !resigning" class="rounded-[16px] bg-[#ECFDF5] border border-[#A7F3D0] p-4 text-sm text-[#065F46] flex items-center justify-between gap-3">
              <span>✓ เซ็นแล้ว</span>
              <button v-if="current.open" @click="startResign" class="text-xs underline text-[#505E5E]">เซ็นใหม่</button>
            </div>

            <div v-else-if="current.open" class="space-y-3">
              <p class="text-sm font-bold text-[#1a1c1c]">เซ็นชื่อ <span class="font-normal text-[#889696] text-xs">— ลายเซ็นจะไปอยู่ในช่องสีเหลือง</span></p>
              <div class="relative border-[1.5px] border-[#CBD5E1] rounded-[12px] bg-white" style="touch-action:none">
                <canvas ref="padEl" class="block w-full h-[190px] rounded-[12px] cursor-crosshair"
                  @pointerdown="padDown" @pointermove="padMove" @pointerup="padUp" @pointercancel="padUp" @pointerleave="padUp"></canvas>
                <div class="absolute left-[18px] right-[18px] bottom-[44px] border-b border-dashed border-[#CBD5E1] pointer-events-none"></div>
                <div v-if="!drawn" class="absolute inset-x-0 bottom-[18px] text-center text-xs text-[#94A3B8] pointer-events-none">ใช้นิ้วหรือเมาส์วาดลายเซ็นตรงนี้</div>
              </div>
              <div class="flex justify-end">
                <button @click="clearPad" class="text-xs underline text-[#505E5E]">ล้างแล้วเซ็นใหม่</button>
              </div>
              <label class="flex items-start gap-2 text-sm text-[#334155]">
                <input type="checkbox" v-model="accepted" class="mt-1 w-4 h-4 shrink-0" />
                <span>ข้าพเจ้า {{ current.passenger.name }} ได้อ่านข้อความในแบบฟอร์มนี้ครบถ้วนแล้ว และยินยอมให้ใช้ลายเซ็นนี้ลงในแบบฟอร์มฉบับนี้</span>
              </label>
              <p v-if="signError" class="text-sm text-[#DC2626] bg-[#FEF2F2] border border-[#FECACA] rounded-[12px] p-3">{{ signError }}</p>
              <button @click="submit" :disabled="!drawn || !accepted || signing"
                class="w-full bg-[#006565] text-white py-3 rounded-[12px] font-bold text-sm hover:bg-[#004D4D] disabled:opacity-50 transition-all">
                {{ signing ? 'กำลังบันทึก…' : 'ยืนยันลายเซ็น' }}
              </button>
              <p class="text-[11px] text-[#889696]">เราเก็บลายเซ็นไว้เฉพาะเพื่อยื่นแบบฟอร์มนี้กับอุทยาน และลบทิ้งหลังจบทริป</p>
            </div>
          </template>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import api from '../lib/axios';
import { useToast } from '../lib/toast';

/**
 * เซ็นแบบฟอร์มอุทยาน — สิทธิ์ว่าใครเซ็นให้ใครได้ และกำหนดเซ็น มาจาก
 * GET /bookings/{ref}/permit-form หน้านี้แค่วาดตามนั้น ดู PermitSignatureService
 * แบบฟอร์มวาดจากภาพหน้าเปล่า + ข้อความในตำแหน่งเดียวกับ PDF (ขนาดเป็น pt ของกระดาษ)
 */
const props = defineProps({
  booking: { type: Object, required: true },
});
const emit = defineEmits(['close', 'changed']);

const toast = useToast();
const PT_PER_MM = 72 / 25.4;

const loading = ref(true);
const loadError = ref('');
const summary = ref(null);
const current = ref(null);
const pageEls = ref([]);
const pageWidths = ref([]);

const padEl = ref(null);
const drawn = ref(false);
const accepted = ref(false);
const signing = ref(false);
const signError = ref('');
const resigning = ref(false);
const pdfLoading = ref(false);

const base = computed(() => `/bookings/${props.booking.booking_ref}/permit-form`);

function close() { emit('close'); }

async function loadSummary() {
  loading.value = true;
  loadError.value = '';
  try {
    const res = await api.get(base.value);
    summary.value = res.data.data;
    if (!summary.value) loadError.value = 'ทริปนี้ไม่ต้องเซ็นแบบฟอร์ม';
  } catch (e) {
    loadError.value = e.response?.data?.message || 'โหลดข้อมูลไม่สำเร็จ';
  } finally {
    loading.value = false;
  }
}

async function openPassenger(p) {
  loading.value = true;
  loadError.value = '';
  try {
    const res = await api.get(`${base.value}/passengers/${p.id}`);
    current.value = res.data.data;
    accepted.value = false;
    drawn.value = false;
    signError.value = '';
    resigning.value = false;
  } catch (e) {
    loadError.value = e.response?.data?.message || 'เปิดแบบฟอร์มไม่สำเร็จ';
  } finally {
    loading.value = false;
  }
  await nextTick();
  measure();
  setupPad();
}

function measure() {
  pageWidths.value = (pageEls.value || []).map((el) => el?.clientWidth || 0);
}

function fieldStyle(f, page, pi) {
  const pxPerPt = (pageWidths.value[pi] || 600) / (page.width_mm * PT_PER_MM);
  return {
    left: `${f.x * 100}%`,
    top: `${f.y * 100}%`,
    width: `${f.w * 100}%`,
    height: `${f.h * 100}%`,
    fontSize: `${f.size * pxPerPt}px`,
    textAlign: f.align,
  };
}

async function copyLink(p) {
  try {
    await navigator.clipboard.writeText(p.sign_url);
    toast.success(`ก๊อปลิงก์ของ ${p.name} แล้ว ส่งให้ทางไลน์ได้เลย`);
  } catch {
    window.prompt('ก๊อปลิงก์นี้ส่งให้เพื่อน', p.sign_url);
  }
}

async function openPdf() {
  // เปิดแท็บไว้ก่อนตอนยังอยู่ในจังหวะคลิก ไม่งั้นเบราว์เซอร์บล็อกป๊อปอัป
  const tab = window.open('', '_blank');
  pdfLoading.value = true;
  try {
    const res = await api.get(`${base.value}/passengers/${current.value.passenger.id}/pdf`, { responseType: 'blob' });
    const url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
    if (tab) tab.location.href = url;
    else window.location.href = url;
  } catch {
    tab?.close();
    toast.error('เปิด PDF ไม่สำเร็จ');
  } finally {
    pdfLoading.value = false;
  }
}

// ─── แผ่นเซ็น — พื้นหลังโปร่งใส เซิร์ฟเวอร์ตัดขอบให้เอง ─────────────
let ctx = null;
let drawing = false;
let last = null;

function setupPad() {
  const canvas = padEl.value;
  if (!canvas) return;
  const ratio = Math.max(window.devicePixelRatio || 1, 1);
  const rect = canvas.getBoundingClientRect();
  canvas.width = Math.round(rect.width * ratio);
  canvas.height = Math.round(rect.height * ratio);
  ctx = canvas.getContext('2d');
  ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  ctx.strokeStyle = '#000';
  ctx.fillStyle = '#000';
  ctx.lineWidth = 2.4;
  drawn.value = false;
}
function point(e) {
  const rect = padEl.value.getBoundingClientRect();
  return { x: e.clientX - rect.left, y: e.clientY - rect.top };
}
function padDown(e) {
  if (!ctx) return;
  e.preventDefault();
  padEl.value.setPointerCapture?.(e.pointerId);
  drawing = true;
  last = point(e);
  ctx.beginPath();
  ctx.arc(last.x, last.y, ctx.lineWidth / 2, 0, Math.PI * 2);
  ctx.fill();
}
function padMove(e) {
  if (!drawing) return;
  e.preventDefault();
  const p = point(e);
  ctx.beginPath();
  ctx.moveTo(last.x, last.y);
  ctx.lineTo(p.x, p.y);
  ctx.stroke();
  last = p;
  drawn.value = true;
}
function padUp() { drawing = false; }
function clearPad() {
  if (!ctx) return;
  ctx.clearRect(0, 0, padEl.value.width, padEl.value.height);
  drawn.value = false;
}

async function startResign() {
  resigning.value = true;
  await nextTick();
  setupPad();
}

async function submit() {
  if (!drawn.value || !accepted.value || signing.value) return;
  signing.value = true;
  signError.value = '';
  try {
    const res = await api.post(`${base.value}/passengers/${current.value.passenger.id}/sign`, {
      signature: padEl.value.toDataURL('image/png'),
      accept: true,
      channel: 'web',
    });
    summary.value = res.data.data;
    toast.success(res.data.message || 'เซ็นแล้ว');
    current.value = null;
    emit('changed');
  } catch (e) {
    signError.value = e.response?.data?.message || 'บันทึกลายเซ็นไม่สำเร็จ ลองใหม่อีกครั้ง';
  } finally {
    signing.value = false;
  }
}

onMounted(() => {
  // ฟอนต์เดียวกับใน PDF ให้ข้อความบนจอวางตรงกับของจริง
  if (!document.getElementById('pf-sarabun')) {
    const link = document.createElement('link');
    link.id = 'pf-sarabun';
    link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=Sarabun:wght@400&display=swap';
    document.head.appendChild(link);
  }
  window.addEventListener('resize', measure);
  loadSummary();
});
onBeforeUnmount(() => window.removeEventListener('resize', measure));
</script>

<style scoped>
.pf-text { font-family: 'Sarabun', sans-serif; color: #000; line-height: 1.15; overflow: visible; }
.pf-sig { border: 1.5px dashed #f59e0b; background: rgba(254, 243, 199, 0.55); border-radius: 4px; display: flex; align-items: flex-end; }
.pf-sig-img { max-width: 100%; max-height: 100%; }
.pf-align-left { margin-right: auto; }
.pf-align-center { margin: 0 auto; }
.pf-align-right { margin-left: auto; }
</style>
