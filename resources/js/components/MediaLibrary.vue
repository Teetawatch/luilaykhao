<template>
  <Teleport to="body">
    <Transition name="modal">
      <div v-if="show" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="$emit('close')"></div>
        
        <div class="bg-white rounded-[2.5rem] w-full max-w-5xl relative z-10 shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
          <!-- Header -->
          <div class="bg-[var(--color-primary)] p-6 md:p-8 text-white relative shrink-0">
            <button @click="$emit('close')" class="absolute top-4 right-4 md:top-6 md:right-6 w-10 h-10 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center transition-all active:scale-95 z-20">
              <span class="material-symbols-rounded">close</span>
            </button>
            <div class="flex items-center gap-4 mb-3">
              <div class="w-12 h-12 rounded-2xl bg-white/20 flex items-center justify-center border border-white/20">
                <span class="material-symbols-rounded text-3xl">photo_library</span>
              </div>
              <h3 class="text-2xl font-black tracking-tight">คลังสื่อ (Media Library)</h3>
            </div>
            <p class="text-white/80 font-bold flex items-center gap-2 text-sm">
              <span class="material-symbols-rounded text-sm">info</span>
              เลือกรูปภาพที่มีอยู่แล้วเพื่อนำมาใช้งาน หรือจัดการลบไฟล์ที่ไม่จำเป็น
            </p>
          </div>

          <!-- Actions -->
          <div class="p-4 border-b border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-4 bg-gray-50/50">
            <div class="flex items-center gap-3 w-full sm:w-auto">
              <input type="file" ref="fileInput" @change="handleUpload" class="hidden" accept="image/*" multiple />
              <button @click="$refs.fileInput.click()" :disabled="uploading" class="flex-1 sm:flex-none bg-[var(--color-accent)] hover:bg-[var(--color-accent)]/90 text-white px-5 py-2.5 rounded-xl text-sm font-black transition-all flex items-center justify-center gap-2 shadow-sm disabled:opacity-50">
                <span class="material-symbols-rounded text-xl" :class="{'animate-spin': uploading}">{{ uploading ? 'sync' : 'upload' }}</span>
                {{ uploading ? 'กำลังอัปโหลด...' : 'อัปโหลดรูปใหม่' }}
              </button>
              <button @click="fetchMedia" class="p-2.5 rounded-xl border border-gray-200 hover:bg-gray-100 transition-all text-gray-500 bg-white" title="รีเฟรช">
                <span class="material-symbols-rounded">refresh</span>
              </button>
            </div>
            <div class="relative w-full max-w-xs">
              <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">search</span>
              <input v-model="search" placeholder="ค้นหาชื่อไฟล์..." class="w-full pl-10 pr-4 py-2.5 rounded-xl border border-gray-200 text-sm focus:ring-2 focus:ring-[var(--color-accent)]/20 outline-none" />
            </div>
          </div>

          <!-- Content -->
          <div class="p-6 overflow-y-auto flex-grow custom-scrollbar min-h-[400px]">
            <div v-if="loading" class="flex flex-col items-center justify-center py-20 text-gray-400">
              <div class="w-12 h-12 border-4 border-[var(--color-accent)] border-t-transparent rounded-full animate-spin mb-4"></div>
              <p class="font-bold">กำลังโหลดรูปภาพ...</p>
            </div>
            <div v-else-if="filteredMedia.length === 0" class="flex flex-col items-center justify-center py-20 text-gray-400">
              <span class="material-symbols-rounded text-6xl mb-4">image_not_supported</span>
              <p class="font-bold text-lg">ไม่พบรูปภาพในคลัง</p>
              <p class="text-sm">ลองอัปโหลดรูปภาพใหม่ หรือเปลี่ยนคำค้นหา</p>
            </div>
            <div v-else class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
              <div v-for="m in filteredMedia" :key="m.filename" 
                class="group relative aspect-square rounded-2xl border-2 border-transparent hover:border-[var(--color-accent)] overflow-hidden cursor-pointer transition-all bg-gray-100 shadow-sm"
                :class="{'border-[var(--color-accent)] shadow-md ring-4 ring-[var(--color-accent)]/10': selected === m.url}"
                @click="select(m)"
              >
                <img :src="m.url" :alt="m.filename" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110" loading="lazy" />
                
                <!-- Overlay Actions -->
                <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                  <button @click.stop="confirmDelete(m)" class="w-9 h-9 rounded-full bg-red-500 text-white flex items-center justify-center hover:bg-red-600 shadow-lg transition-transform hover:scale-110" title="ลบรูป">
                    <span class="material-symbols-rounded text-xl">delete</span>
                  </button>
                  <button @click.stop="preview(m)" class="w-9 h-9 rounded-full bg-white text-gray-800 flex items-center justify-center hover:bg-gray-100 shadow-lg transition-transform hover:scale-110" title="ขยาย">
                    <span class="material-symbols-rounded text-xl">visibility</span>
                  </button>
                </div>

                <!-- Info -->
                <div class="absolute bottom-0 left-0 right-0 bg-black/60 p-2 text-[10px] text-white backdrop-blur-sm transform translate-y-full group-hover:translate-y-0 transition-transform">
                  <p class="truncate font-bold">{{ m.filename }}</p>
                  <p class="opacity-70">{{ formatSize(m.size) }}</p>
                </div>

                <!-- Selected Indicator -->
                <div v-if="selected === m.url" class="absolute top-2 right-2 w-6 h-6 rounded-full bg-[var(--color-accent)] text-white flex items-center justify-center shadow-lg animate-in zoom-in">
                  <span class="material-symbols-rounded text-sm">check</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer -->
          <div class="p-6 border-t border-gray-100 flex items-center justify-between bg-gray-50/50">
            <p class="text-xs text-gray-400 font-bold">
              {{ filteredMedia.length }} ไฟล์ | ค้นหาจากทั้งหมด {{ media.length }} ไฟล์
            </p>
            <div class="flex gap-3">
              <button @click="$emit('close')" class="px-6 py-2.5 rounded-xl text-sm font-black text-gray-500 hover:bg-gray-100 transition-all">
                ยกเลิก
              </button>
              <button @click="confirmSelection" :disabled="!selected" class="bg-[var(--color-primary)] hover:bg-[var(--color-accent)] text-white px-8 py-2.5 rounded-xl text-sm font-black transition-all shadow-lg shadow-[var(--color-primary)]/20 disabled:opacity-50 disabled:cursor-not-allowed active:scale-95">
                เลือกใช้งาน
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>

  <!-- Image Preview Modal -->
  <Teleport to="body">
    <div v-if="previewing" class="fixed inset-0 z-[110] flex items-center justify-center p-8 bg-black/90 backdrop-blur-md" @click="previewing = null">
      <img :src="previewing.url" class="max-w-full max-h-full rounded-2xl shadow-2xl object-contain" />
      <button class="absolute top-6 right-6 text-white bg-white/20 p-2 rounded-full hover:bg-white/30" @click.stop="previewing = null">
        <span class="material-symbols-rounded">close</span>
      </button>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, onMounted, computed, watch } from 'vue';
import api from '../lib/axios';

const props = defineProps({
  show: Boolean,
  initialSelection: String
});

const emit = defineEmits(['close', 'select']);

const media = ref([]);
const loading = ref(false);
const uploading = ref(false);
const search = ref('');
const selected = ref(props.initialSelection || null);
const previewing = ref(null);

watch(() => props.show, (newVal) => {
  if (newVal) {
    fetchMedia();
    selected.value = props.initialSelection || null;
  }
});

const fetchMedia = async () => {
  loading.value = true;
  try {
    const res = await api.get('/admin/media');
    media.value = res.data.data;
  } catch (e) {
    console.error('Failed to fetch media', e);
  } finally {
    loading.value = false;
  }
};

const filteredMedia = computed(() => {
  if (!search.value) return media.value;
  const q = search.value.toLowerCase();
  return media.value.filter(m => m.filename.toLowerCase().includes(q));
});

const select = (m) => {
  selected.value = m.url;
};

const preview = (m) => {
  previewing.value = m;
};

const confirmSelection = () => {
  emit('select', selected.value);
  emit('close');
};

const handleUpload = async (event) => {
  const files = Array.from(event.target.files);
  if (!files.length) return;
  
  uploading.value = true;
  try {
    const promises = files.map(file => {
      const formData = new FormData();
      formData.append('file', file);
      return api.post('/admin/upload-image', formData);
    });
    await Promise.all(promises);
    await fetchMedia();
  } catch (e) {
    alert('อัปโหลดไฟล์บางส่วนล้มเหลว');
  } finally {
    uploading.value = false;
    event.target.value = '';
  }
};

const confirmDelete = async (m) => {
  if (confirm(`ยืนยันการลบไฟล์ "${m.filename}"? ไฟล์นี้จะหายไปจากเซิร์ฟเวอร์ทันทีและอาจส่งผลกระทบต่อทริปที่ใช้รูปนี้อยู่`)) {
    try {
      await api.delete('/admin/media', { data: { filename: m.filename } });
      media.value = media.value.filter(item => item.filename !== m.filename);
      if (selected.value === m.url) selected.value = null;
    } catch (e) {
      alert('ลบไฟล์ไม่สำเร็จ');
    }
  }
};

const formatSize = (bytes) => {
  if (!bytes) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};

onMounted(() => {
  if (props.show) fetchMedia();
});
</script>

<style scoped>
.modal-enter-active, .modal-leave-active { transition: opacity 0.3s ease; }
.modal-enter-from, .modal-leave-to { opacity: 0; }
.modal-enter-active .bg-white { animation: zoomIn 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes zoomIn {
  from { transform: scale(0.95); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}

.custom-scrollbar::-webkit-scrollbar { width: 6px; }
.custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
