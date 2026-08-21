<template>
  <!-- ทริปที่ไม่ได้ขอเอกสารอะไร ไม่ต้องมีกล่องนี้เลย -->
  <section v-if="requirements.length" class="bg-white rounded-[2.5rem] border border-gray-100 overflow-hidden">
    <div class="px-8 py-5 border-b border-gray-100 flex items-center gap-3"
      :class="hasMissing ? 'bg-amber-50' : 'bg-gray-50'">
      <span class="material-symbols-rounded text-[22px]"
        :class="hasMissing ? 'text-amber-600' : 'text-violet-600'">attach_file</span>
      <div class="flex-1">
        <h2 class="font-black text-gray-900">{{ hasMissing ? 'ยังขาดเอกสารแนบ' : 'เอกสารแนบ' }}</h2>
        <p class="text-xs text-gray-600 mt-0.5">
          {{ hasMissing
            ? 'ทริปนี้ต้องใช้เอกสารของผู้เดินทางทุกท่าน แนบเป็นรูปหรือไฟล์ PDF ก็ได้'
            : 'ทีมงานได้รับเอกสารของทุกท่านแล้ว แนบผิดไฟล์สามารถลบแล้วแนบใหม่ได้' }}
        </p>
      </div>
    </div>

    <div class="p-6 sm:p-8 space-y-6">
      <div v-for="person in passengers" :key="person.passenger_id"
        class="rounded-3xl border border-gray-100 bg-gray-50/60 p-5 space-y-5">
        <p class="font-black text-gray-900 text-sm">{{ person.name || 'ผู้เดินทาง' }}</p>

        <div v-for="req in person.requirements" :key="req.key">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="text-sm font-bold text-gray-800">{{ req.label }}</span>
            <span v-if="req.is_missing" class="px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-[11px] font-bold">ยังไม่แนบ</span>
            <span v-else-if="!req.required" class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 text-[11px] font-bold">ไม่บังคับ</span>
          </div>
          <p v-if="req.note" class="text-xs text-gray-600 mt-1">{{ req.note }}</p>

          <div v-if="req.files.length" class="mt-2 space-y-2">
            <div v-for="file in req.files" :key="file.id"
              class="flex items-center gap-2 bg-white border border-gray-200 rounded-2xl px-3 py-2.5">
              <span class="material-symbols-rounded text-[18px] text-violet-500 shrink-0">
                {{ file.is_image ? 'image' : 'picture_as_pdf' }}
              </span>
              <span class="text-xs font-bold text-gray-700 truncate flex-1">{{ file.original_name }}</span>
              <a :href="file.url" target="_blank" rel="noopener" title="เปิดดู"
                class="text-gray-400 hover:text-teal-600 shrink-0">
                <span class="material-symbols-rounded text-[18px]">open_in_new</span>
              </a>
              <button type="button" title="ลบไฟล์นี้" :disabled="deletingId === file.id"
                @click="removeFile(file)" class="text-gray-400 hover:text-red-500 shrink-0 disabled:opacity-40">
                <span class="material-symbols-rounded text-[18px]">delete</span>
              </button>
            </div>
          </div>

          <label v-if="req.files.length < maxFiles"
            class="mt-2 flex items-center justify-center gap-2 w-full py-3 rounded-2xl border-2 border-dashed border-violet-200 bg-white text-violet-600 font-bold text-sm cursor-pointer hover:border-violet-400 transition-all"
            :class="uploadingSlot === `${person.passenger_id}:${req.key}` ? 'opacity-60 pointer-events-none' : ''">
            <span class="material-symbols-rounded text-[18px]">
              {{ uploadingSlot === `${person.passenger_id}:${req.key}` ? 'hourglass_top' : 'upload_file' }}
            </span>
            {{ uploadingSlot === `${person.passenger_id}:${req.key}`
              ? 'กำลังอัปโหลด...'
              : (req.files.length ? 'แนบไฟล์เพิ่ม' : 'เลือกไฟล์') }}
            <input type="file" class="hidden" accept="image/*,.pdf,application/pdf"
              @change="uploadFile(person.passenger_id, req.key, $event)" />
          </label>
        </div>
      </div>

      <p class="text-xs text-gray-500 text-center">
        ไฟล์ละไม่เกิน {{ maxSizeMb }} MB · เอกสารเหล่านี้เห็นได้เฉพาะท่านและทีมงานเท่านั้น
      </p>
    </div>
  </section>
</template>

<script setup>
/**
 * เอกสารแนบของการจอง — ดู แนบเพิ่ม และลบ
 *
 * รายการที่ต้องแนบมาจากที่แอดมินตั้งไว้บนทริป ส่วนไฟล์ผูกกับผู้เดินทางรายคน
 * อัปโหลดทันทีที่เลือกไฟล์ ไม่มีปุ่ม "บันทึก" — ไฟล์ที่เลือกแล้วแต่ยังไม่ส่ง
 * คือไฟล์ที่ลูกค้าคิดว่าส่งแล้ว
 */
import { computed, ref } from 'vue';
import api from '../lib/axios';
import { useToast } from '../lib/toast';

const props = defineProps({
  bookingRef: { type: String, required: true },
  // payload จาก BookingResource.documents
  documents: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['updated']);

const toast = useToast();
const maxFiles = 5;
const maxSizeMb = 10;

// สำเนาที่แก้ได้ในเครื่อง — อัปเดตทันทีหลังอัปโหลด/ลบ โดยไม่ต้องรอโหลดการจองใหม่
const local = ref(null);
const payload = computed(() => local.value || props.documents || {});
const requirements = computed(() => payload.value.requirements || []);
const passengers = computed(() => payload.value.passengers || []);
const hasMissing = computed(() => payload.value.has_missing === true);

const uploadingSlot = ref('');
const deletingId = ref(null);

async function uploadFile(passengerId, requirementKey, event) {
  const file = event.target.files?.[0];
  // ล้าง input ทันที ไม่งั้นเลือกไฟล์ชื่อเดิมซ้ำจะไม่ยิง change อีก
  event.target.value = '';
  if (!file || uploadingSlot.value) return;

  if (file.size > maxSizeMb * 1024 * 1024) {
    toast.error(`ไฟล์ใหญ่เกิน ${maxSizeMb} MB`);
    return;
  }
  if (!file.type.startsWith('image/') && file.type !== 'application/pdf') {
    toast.error('รองรับไฟล์รูปภาพและ PDF เท่านั้น');
    return;
  }

  uploadingSlot.value = `${passengerId}:${requirementKey}`;
  try {
    const form = new FormData();
    form.append('passenger_id', passengerId);
    form.append('requirement_key', requirementKey);
    form.append('file', file);
    const res = await api.post(`/bookings/${props.bookingRef}/documents`, form);
    local.value = res.data.data.documents;
    emit('updated', local.value);
    toast.success('แนบเอกสารแล้ว');
  } catch (e) {
    toast.error(e?.response?.data?.message || 'แนบเอกสารไม่สำเร็จ');
  } finally {
    uploadingSlot.value = '';
  }
}

async function removeFile(file) {
  if (deletingId.value) return;
  if (!confirm(`ลบเอกสาร "${file.original_name}" ใช่หรือไม่?\nลบแล้วต้องแนบใหม่ครับ`)) return;

  deletingId.value = file.id;
  try {
    const res = await api.delete(`/bookings/${props.bookingRef}/documents/${file.id}`);
    local.value = res.data.data;
    emit('updated', local.value);
    toast.success('ลบเอกสารแล้ว');
  } catch (e) {
    toast.error(e?.response?.data?.message || 'ลบเอกสารไม่สำเร็จ');
  } finally {
    deletingId.value = null;
  }
}
</script>
