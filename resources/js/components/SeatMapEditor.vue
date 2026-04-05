<template>
  <div class="seat-editor bg-white rounded-2xl">
    <!-- Controls -->
    <div class="editor-controls mb-8 p-6 bg-slate-50 rounded-2xl border border-slate-100 shadow-sm">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="form-group">
          <label class="block text-sm font-extrabold text-slate-700 mb-2 uppercase tracking-wider">จำนวนแถวที่นั่ง</label>
          <div class="flex items-center gap-3">
            <button type="button" @click="updateRows(-1)" class="w-10 h-10 flex items-center justify-center rounded-full bg-white border border-slate-200 hover:bg-slate-100 transition-colors shadow-sm">
              <i class="fas fa-minus text-slate-500"></i>
            </button>
            <input 
              type="number" 
              v-model.number="layout.rows" 
              min="1" max="12"
              class="w-20 text-center font-black text-lg border-slate-200 rounded-xl focus:ring-[var(--color-primary)] focus:border-[var(--color-primary)]"
            />
            <button type="button" @click="updateRows(1)" class="w-10 h-10 flex items-center justify-center rounded-full bg-white border border-slate-200 hover:bg-slate-100 transition-colors shadow-sm">
              <i class="fas fa-plus text-slate-500"></i>
            </button>
          </div>
        </div>
        <div class="form-group">
          <label class="block text-sm font-extrabold text-slate-700 mb-2 uppercase tracking-wider">
            กำหนดคอลัมน์ (แยกด้วยคอมม่า)
          </label>
          <div class="relative">
            <input 
              type="text" 
              v-model="columnsRaw" 
              @input="onColumnsInput"
              placeholder="A,B,C,,D,E"
              class="w-full font-bold border-slate-200 rounded-xl focus:ring-[var(--color-primary)] focus:border-[var(--color-primary)] pr-10"
            />
            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-300 pointer-events-none">
              <i class="fas fa-columns"></i>
            </span>
          </div>
          <p class="text-[10px] text-slate-400 mt-2 font-medium">เว้นว่างไว้เป็นทางเดิน (e.g. A,B,,C,D)</p>
        </div>
      </div>
      
      <div class="mt-6 pt-6 border-t border-slate-200 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-2 text-xs font-bold text-slate-500">
          <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
          คลิกที่บล็อก {{ totalSeats }} จุดเพื่อเปิด/ปิดที่นั่ง
        </div>
        <div class="flex gap-3">
          <button type="button" @click="resetToVan" class="px-4 py-2 rounded-lg bg-white border border-slate-200 text-xs font-black text-slate-600 hover:bg-slate-50 transition-colors flex items-center gap-2">
            <i class="fas fa-shuttle-van"></i> รีเซ็ตเป็นรถตู้ VIP
          </button>
          <button type="button" @click="fillAll" class="px-4 py-2 rounded-lg bg-blue-50 text-blue-600 border border-blue-100 text-xs font-black hover:bg-blue-100 transition-colors flex items-center gap-2">
            <i class="fas fa-check-double"></i> เลือกทั้งหมด
          </button>
        </div>
      </div>
    </div>

    <!-- Visual Editor Section -->
    <div class="visual-grid-container p-8 border-2 border-slate-100 rounded-[2.5rem] bg-white overflow-x-auto min-h-[500px] flex items-center justify-center">
      <!-- Van/Vehicle Shell -->
      <div class="vehicle-shell relative bg-slate-50 border-4 border-slate-200 shadow-xl rounded-[3rem] p-8 min-w-[300px] w-full max-w-sm">
        
        <!-- Dashboard / Front Section -->
        <div class="front-dashboard flex justify-between items-center mb-12 border-b-2 border-dashed border-slate-200 pb-8 px-4">
          <div class="w-14 h-14 rounded-2xl bg-slate-200 flex items-center justify-center text-slate-400 shadow-inner">
             <i class="fas fa-steering-wheel text-xl opacity-60"></i>
          </div>
          <div class="text-center">
            <div class="w-16 h-1.5 bg-slate-200 rounded-full mx-auto mb-2 opacity-50"></div>
            <span class="text-[10px] font-black text-slate-300 uppercase tracking-widest">FRONT / ด้านหน้าทริป</span>
          </div>
          <div 
            class="w-14 h-14 rounded-2xl border-2 flex items-center justify-center cursor-pointer transition-all duration-300 transform active:scale-95 shadow-sm"
            :class="hasSeat(getFirstCol() + '1') ? 'bg-[var(--color-primary)] border-[var(--color-primary)] text-white font-black' : 'bg-white border-slate-100 text-slate-200 border-dashed'"
            @click="toggleSeat(getFirstCol(), 1)"
          >
            <div class="flex flex-col items-center">
              <i class="fas fa-user text-[10px] mb-0.5"></i>
              <span class="text-[10px]">{{ getFirstCol() }}1</span>
            </div>
          </div>
        </div>

        <!-- Passenger Rows -->
        <div class="rows-container space-y-4">
          <div v-for="r in layout.rows" :key="r" class="flex justify-center gap-3">
            <!-- Row Number Label -->
            <div v-if="r > 1" class="w-6 flex items-center justify-center text-[10px] font-black text-slate-300 uppercase shrink-0">
              R{{ r }}
            </div>
            <div v-else class="w-6 shrink-0"></div>

            <template v-for="(col, cIdx) in columns" :key="cIdx">
              <!-- Aisle Spacer -->
              <div v-if="col === ''" class="w-4 flex items-center justify-center">
                <div class="w-0.5 h-full bg-slate-100 rounded-full opacity-50 border-r border-slate-200 border-dashed"></div>
              </div>
              
              <!-- Seat Block -->
              <div 
                v-else-if="r > 1 || true"
                class="w-12 h-12 md:w-14 md:h-14 rounded-2xl border-2 flex items-center justify-center cursor-pointer transition-all duration-300 transform active:scale-95 shadow-sm"
                :class="hasSeat(col + r) 
                  ? 'bg-[var(--color-primary)] border-[var(--color-primary)] text-white shadow-md' 
                  : 'bg-white border-slate-100 text-slate-200 border-dashed hover:border-slate-300'"
                @click="toggleSeat(col, r)"
              >
                <div class="flex flex-col items-center">
                   <i v-if="hasSeat(col+r)" class="fas fa-check-circle text-[10px] mb-0.5"></i>
                   <i v-else class="fas fa-plus text-[8px] mb-0.5"></i>
                   <span class="text-[11px] font-extrabold uppercase">{{ col }}{{ r }}</span>
                </div>
              </div>
            </template>
          </div>
        </div>

        <!-- Back Label -->
        <div class="mt-12 text-center">
          <span class="text-[10px] font-black text-slate-300 uppercase tracking-widest bg-slate-100 px-4 py-1 rounded-full">REAR / ด้านหลัง</span>
        </div>
      </div>
    </div>

    <!-- Summary -->
    <div class="mt-6 flex items-center justify-between text-sm px-4">
       <span class="font-extrabold text-slate-500">สรุป: แผนที่แบบ {{ layout.rows }} แถว | {{ totalSeats }} ที่นั่ง</span>
       <span class="text-xs text-slate-400 font-medium">บันทึกอัตโนมัติลงในข้อมูลยานพาหนะ</span>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';

const props = defineProps({
  modelValue: { 
    type: Object, 
    default: () => ({ rows: 4, columns: ['A','B','C','','D','E'], seats: [] }) 
  }
});
const emit = defineEmits(['update:modelValue']);

// Initialize with deep copy
const layout = ref(JSON.parse(JSON.stringify(props.modelValue || { rows: 4, columns: ['A','B','C','','D','E'], seats: [] })));

// Sync columns raw input
const columnsRaw = ref(layout.value.columns ? layout.value.columns.join(',') : "A,B,,C,D");

const columns = computed(() => columnsRaw.value.split(',').map(c => c.trim()));
const totalSeats = computed(() => layout.value.seats?.length || 0);

// Sync with parent
watch(layout, (newVal) => {
  emit('update:modelValue', newVal);
}, { deep: true });

function getFirstCol() {
  return columns.value.find(c => c !== '') || 'A';
}

function onColumnsInput() {
  layout.value.columns = columns.value;
}

function updateRows(delta) {
  const newRows = (layout.value.rows || 1) + delta;
  if (newRows >= 1 && newRows <= 12) {
    layout.value.rows = newRows;
    // Cleanup seats that are now out of bounds
    layout.value.seats = layout.value.seats.filter(s => s.row <= newRows);
  }
}

function hasSeat(id) {
  return layout.value.seats?.some(s => s.id === id);
}

function toggleSeat(col, row) {
  if (!layout.value.seats) layout.value.seats = [];
  const id = `${col}${row}`;
  const idx = layout.value.seats.findIndex(s => s.id === id);
  
  if (idx >= 0) {
    layout.value.seats.splice(idx, 1);
  } else {
    layout.value.seats.push({ id, row, col, label: id });
  }
}

function fillAll() {
  const newSeats = [];
  for (let r = 1; r <= layout.value.rows; r++) {
    for (const col of columns.value) {
      if (col !== '') {
        const id = `${col}${r}`;
        newSeats.push({ id, row: r, col, label: id });
      }
    }
  }
  layout.value.seats = newSeats;
}

function resetToVan() {
  if (confirm('คุณต้องการรีเซ็ตเป็นรูปแบบรถตู้ VIP มาตรฐานใช่หรือไม่?')) {
    layout.value.rows = 4;
    columnsRaw.value = "A,B,C,,D,E";
    layout.value.columns = ["A","B","C","","D","E"];
    layout.value.seats = [
      {id:'A1', row:1, col:'A', label:'A1'},
      {id:'A2', row:2, col:'A', label:'A2'}, {id:'D2', row:2, col:'D', label:'D2'}, {id:'E2', row:2, col:'E', label:'E2'},
      {id:'A3', row:3, col:'A', label:'A3'}, {id:'D3', row:3, col:'D', label:'D3'}, {id:'E3', row:3, col:'E', label:'E3'},
      {id:'A4', row:4, col:'A', label:'A4'}, {id:'B4', row:4, col:'B', label:'B4'}, {id:'C4', row:4, col:'C', label:'C4'},
    ];
  }
}

onMounted(() => {
  // Ensure default structure if missing
  if (!layout.value.columns) layout.value.columns = ["A","B","C","","D","E"];
  if (!layout.value.seats) layout.value.seats = [];
  columnsRaw.value = layout.value.columns.join(',');
});
</script>

<style scoped>
.vehicle-shell {
  background-image: 
    radial-gradient(circle at 10px 10px, rgba(0,0,0,0.02) 1px, transparent 0);
  background-size: 20px 20px;
}
</style>
