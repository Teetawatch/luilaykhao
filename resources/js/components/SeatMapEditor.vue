<template>
  <div class="seat-editor bg-white rounded-2xl">
    <!-- Controls -->
    <div class="editor-controls mb-8 p-6 bg-slate-50 rounded-2xl border border-slate-100">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="form-group">
          <label class="block text-sm font-extrabold text-slate-700 mb-2 uppercase tracking-wider">จำนวนแถวที่นั่ง</label>
          <div class="flex items-center gap-3">
            <button type="button" @click="updateRows(-1)" class="w-10 h-10 flex items-center justify-center rounded-full bg-white border border-slate-200 hover:bg-slate-100 transition-colors">
              <span class="material-symbols-rounded text-slate-500" style="font-size:18px">remove</span>
            </button>
            <input 
              type="number" 
              v-model.number="layout.rows" 
              min="1" max="12"
              class="w-20 text-center font-black text-lg border-slate-200 rounded-xl focus:ring-[var(--color-primary)] focus:border-[var(--color-primary)]"
            />
            <button type="button" @click="updateRows(1)" class="w-10 h-10 flex items-center justify-center rounded-full bg-white border border-slate-200 hover:bg-slate-100 transition-colors">
              <span class="material-symbols-rounded text-slate-500" style="font-size:18px">add</span>
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
              <span class="material-symbols-rounded" style="font-size:18px">view_week</span>
            </span>
          </div>
          <p class="text-[10px] text-slate-400 mt-2 font-medium">เว้นว่างไว้เป็นทางเดิน (e.g. A,B,,C,D)</p>
        </div>
      </div>

      <!-- Advanced config -->
      <div class="mt-6 pt-6 border-t border-slate-200">
        <div class="flex items-center gap-2 mb-4">
          <button type="button" @click="showAdvanced = !showAdvanced" class="text-xs font-black text-slate-500 flex items-center gap-1.5 hover:text-slate-700 transition-colors">
            <span class="material-symbols-rounded" style="font-size:16px">{{ showAdvanced ? 'expand_more' : 'chevron_right' }}</span>
            <span class="material-symbols-rounded" style="font-size:16px">settings</span> ตั้งค่าขั้นสูง
          </button>
        </div>
        <div v-if="showAdvanced" class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
          <div class="form-group">
            <label class="block text-sm font-extrabold text-slate-700 mb-2 uppercase tracking-wider">ที่นั่งด้านหน้า (แยกจากแถวปกติ)</label>
            <input 
              type="text" 
              v-model="frontSeatRaw" 
              @input="onFrontSeatInput"
              placeholder="เช่น A1 (เว้นว่างถ้าไม่มี)"
              class="w-full font-bold border-slate-200 rounded-xl focus:ring-[var(--color-primary)] focus:border-[var(--color-primary)]"
            />
            <p class="text-[10px] text-slate-400 mt-2 font-medium">ที่นั่งคู่คนขับ (จะแสดงแถวบนสุดแยกออกมา)</p>
          </div>
          <div class="form-group">
            <label class="block text-sm font-extrabold text-slate-700 mb-2 uppercase tracking-wider">ที่นั่งแถวสุดท้ายรวมกลาง</label>
            <input 
              type="text" 
              v-model="lastRowCenterRaw" 
              @input="onLastRowCenterInput"
              placeholder="เช่น A4,B4,C4 (เว้นว่างถ้าไม่มี)"
              class="w-full font-bold border-slate-200 rounded-xl focus:ring-[var(--color-primary)] focus:border-[var(--color-primary)]"
            />
            <p class="text-[10px] text-slate-400 mt-2 font-medium">ที่นั่งที่จะจัดกลุ่มตรงกลาง (เช่น แถวหลังสุด)</p>
          </div>
          <div class="form-group">
            <label class="block text-sm font-extrabold text-slate-700 mb-2 uppercase tracking-wider">ป้ายด้านหน้า</label>
            <input 
              type="text" 
              v-model="layout.front_label" 
              placeholder="หน้ารถ"
              class="w-full font-bold border-slate-200 rounded-xl focus:ring-[var(--color-primary)] focus:border-[var(--color-primary)]"
            />
          </div>
          <div class="form-group">
            <label class="block text-sm font-extrabold text-slate-700 mb-2 uppercase tracking-wider">ป้ายด้านหลัง</label>
            <input 
              type="text" 
              v-model="layout.rear_label" 
              placeholder="ท้ายรถ (สำหรับเก็บสัมภาระ)"
              class="w-full font-bold border-slate-200 rounded-xl focus:ring-[var(--color-primary)] focus:border-[var(--color-primary)]"
            />
          </div>
          <div class="form-group">
            <label class="block text-sm font-extrabold text-slate-700 mb-2 uppercase tracking-wider">ไอคอนคนขับ</label>
            <select v-model="layout.driver_icon" class="w-full font-bold border-slate-200 rounded-xl focus:ring-[var(--color-primary)] focus:border-[var(--color-primary)]">
              <option value="directions_car">รถยนต์</option>
              <option value="sailing">เรือ</option>
              <option value="directions_bus">รถบัส</option>
              <option value="two_wheeler">มอเตอร์ไซค์</option>
            </select>
          </div>
          <div class="form-group flex items-end">
            <label class="flex items-center gap-3 cursor-pointer">
              <input type="checkbox" v-model="layout.show_driver" class="w-5 h-5 rounded border-slate-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]" />
              <span class="text-sm font-extrabold text-slate-700 uppercase tracking-wider">แสดงช่องคนขับ</span>
            </label>
          </div>
        </div>
      </div>
      
      <div class="mt-4 pt-4 border-t border-slate-200 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-2 text-xs font-bold text-slate-500">
          <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
          คลิกที่บล็อก {{ totalSeats }} จุดเพื่อเปิด/ปิดที่นั่ง
        </div>
        <div class="flex gap-3">
          <button type="button" @click="resetToVan" class="px-4 py-2 rounded-lg bg-white border border-slate-200 text-xs font-black text-slate-600 hover:bg-slate-50 transition-colors flex items-center gap-2">
            <span class="material-symbols-rounded" style="font-size:16px">airport_shuttle</span> รีเซ็ตเป็นรถตู้ VIP
          </button>
          <button type="button" @click="resetToBus" class="px-4 py-2 rounded-lg bg-white border border-slate-200 text-xs font-black text-slate-600 hover:bg-slate-50 transition-colors flex items-center gap-2">
            <span class="material-symbols-rounded" style="font-size:16px">directions_bus</span> รีเซ็ตเป็นรถบัส
          </button>
          <button type="button" @click="fillAll" class="px-4 py-2 rounded-lg bg-blue-50 text-blue-600 border border-blue-100 text-xs font-black hover:bg-blue-100 transition-colors flex items-center gap-2">
            <span class="material-symbols-rounded" style="font-size:16px">done_all</span> เลือกทั้งหมด
          </button>
          <button type="button" @click="clearAll" class="px-4 py-2 rounded-lg bg-red-50 text-red-500 border border-red-100 text-xs font-black hover:bg-red-100 transition-colors flex items-center gap-2">
            <span class="material-symbols-rounded" style="font-size:16px">close</span> ล้างทั้งหมด
          </button>
        </div>
      </div>
    </div>

    <!-- Visual Editor Section -->
    <div class="visual-grid-container p-8 border-2 border-slate-100 rounded-[2.5rem] bg-white overflow-x-auto min-h-[500px] flex items-center justify-center">
      <!-- Van/Vehicle Shell -->
      <div class="vehicle-shell relative bg-slate-50 border-4 border-slate-200 rounded-[3rem] p-8 min-w-[300px] w-full max-w-sm">
        
        <!-- Dashboard / Front Section -->
        <div class="front-dashboard flex justify-between items-center mb-12 border-b-2 border-dashed border-slate-200 pb-8 px-4">
          <div v-if="layout.show_driver !== false" class="w-14 h-14 rounded-2xl bg-slate-200 flex items-center justify-center text-slate-400">
             <span class="material-symbols-rounded text-xl opacity-60" style="font-variation-settings:'FILL' 0,'wght' 400">{{ layout.driver_icon || 'directions_car' }}</span>
          </div>
          <div v-else class="w-14 shrink-0"></div>
          <div class="text-center">
            <div class="w-16 h-1.5 bg-slate-200 rounded-full mx-auto mb-2 opacity-50"></div>
            <span class="text-[10px] font-black text-slate-300 uppercase tracking-widest">{{ layout.front_label || 'หน้ารถ' }}</span>
          </div>
          <div 
            v-if="layout.front_seat"
            class="w-14 h-14 rounded-2xl border-2 flex items-center justify-center cursor-pointer transition-all duration-300 transform active:scale-95"
            :class="hasSeat(layout.front_seat) ? 'bg-[var(--color-primary)] border-[var(--color-primary)] text-white font-black' : 'bg-white border-slate-100 text-slate-200 border-dashed'"
            @click="toggleSeatById(layout.front_seat)"
          >
            <div class="flex flex-col items-center">
              <span class="material-symbols-rounded mb-0.5" style="font-size:14px">person</span>
              <span class="text-[10px]">{{ layout.front_seat }}</span>
            </div>
          </div>
          <div v-else class="w-14 shrink-0"></div>
        </div>

        <!-- Passenger Rows -->
        <div class="rows-container space-y-4">
          <div v-for="r in layout.rows" :key="r" class="flex justify-center gap-3">
            <!-- Row Number Label -->
            <div class="w-6 flex items-center justify-center text-[10px] font-black text-slate-300 uppercase shrink-0">
              R{{ r }}
            </div>

            <template v-for="(col, cIdx) in columns" :key="cIdx">
              <!-- Aisle Spacer -->
              <div v-if="col === ''" class="w-4 flex items-center justify-center">
                <div class="w-0.5 h-full bg-slate-100 rounded-full opacity-50 border-r border-slate-200 border-dashed"></div>
              </div>
              
              <!-- Seat Block -->
              <div 
                v-else
                class="w-12 h-12 md:w-14 md:h-14 rounded-2xl border-2 flex items-center justify-center cursor-pointer transition-all duration-300 transform active:scale-95"
                :class="seatEditorClass(col, r)"
                @click="toggleSeat(col, r)"
              >
                <div class="flex flex-col items-center">
                   <span v-if="hasSeat(col+r)" class="material-symbols-rounded mb-0.5" style="font-size:14px">check_circle</span>
                   <span v-else class="material-symbols-rounded mb-0.5" style="font-size:12px">add</span>
                   <span class="text-[11px] font-extrabold uppercase">{{ col }}{{ r }}</span>
                </div>
              </div>
            </template>
          </div>
        </div>

        <!-- Back Label -->
        <div class="mt-12 text-center">
          <span class="text-[10px] font-black text-slate-300 uppercase tracking-widest bg-slate-100 px-4 py-1 rounded-full">{{ layout.rear_label || 'ท้ายรถ' }}</span>
        </div>
      </div>
    </div>

    <!-- Indicators -->
    <div class="mt-4 flex flex-wrap gap-4 px-4 text-xs">
      <div class="flex items-center gap-2">
        <div class="w-5 h-5 rounded-lg bg-[var(--color-primary)]"></div>
        <span class="text-slate-500 font-bold">ที่นั่งเปิด</span>
      </div>
      <div class="flex items-center gap-2">
        <div class="w-5 h-5 rounded-lg bg-white border-2 border-dashed border-slate-200"></div>
        <span class="text-slate-500 font-bold">ที่นั่งปิด</span>
      </div>
      <div v-if="layout.front_seat" class="flex items-center gap-2">
        <div class="w-5 h-5 rounded-lg bg-amber-500"></div>
        <span class="text-slate-500 font-bold">ที่นั่งด้านหน้า ({{ layout.front_seat }})</span>
      </div>
      <div v-if="lastRowCenterSeats.length" class="flex items-center gap-2">
        <div class="w-5 h-5 rounded-lg bg-purple-500"></div>
        <span class="text-slate-500 font-bold">แถวรวมกลาง ({{ lastRowCenterSeats.join(', ') }})</span>
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

const DEFAULT_LAYOUT = { 
  rows: 4, columns: ['A','B','C','','D','E'], seats: [],
  front_seat: null, last_row_center: [], 
  front_label: 'หน้ารถ', rear_label: 'ท้ายรถ (สำหรับเก็บสัมภาระ)',
  driver_icon: 'directions_car', show_driver: true,
};

const props = defineProps({
  modelValue: { 
    type: Object, 
    default: () => ({
      rows: 4, columns: ['A','B','C','','D','E'], seats: [],
      front_seat: null, last_row_center: [],
      front_label: 'หน้ารถ', rear_label: 'ท้ายรถ (สำหรับเก็บสัมภาระ)',
      driver_icon: 'directions_car', show_driver: true,
    })
  }
});
const emit = defineEmits(['update:modelValue']);

// Initialize with deep copy
const layout = ref(JSON.parse(JSON.stringify({ ...DEFAULT_LAYOUT, ...props.modelValue })));

// Sync columns raw input
const columnsRaw = ref(layout.value.columns ? layout.value.columns.join(',') : "A,B,,C,D");
const showAdvanced = ref(false);

// Front seat raw
const frontSeatRaw = ref(layout.value.front_seat || '');
// Last row center raw
const lastRowCenterRaw = ref((layout.value.last_row_center || []).join(','));

const columns = computed(() => columnsRaw.value.split(',').map(c => c.trim()));
const totalSeats = computed(() => layout.value.seats?.length || 0);
const lastRowCenterSeats = computed(() => (layout.value.last_row_center || []).filter(Boolean));

// Sync with parent
watch(layout, (newVal) => {
  emit('update:modelValue', newVal);
}, { deep: true });

function onFrontSeatInput() {
  const val = frontSeatRaw.value.trim().toUpperCase();
  layout.value.front_seat = val || null;
}

function onLastRowCenterInput() {
  layout.value.last_row_center = lastRowCenterRaw.value
    .split(',')
    .map(s => s.trim().toUpperCase())
    .filter(Boolean);
}

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

function seatEditorClass(col, row) {
  const id = `${col}${row}`;
  const active = hasSeat(id);
  const isFront = layout.value.front_seat === id;
  const isCenter = (layout.value.last_row_center || []).includes(id);

  if (active && isFront) return 'bg-amber-500 border-amber-500 text-white';
  if (active && isCenter) return 'bg-purple-500 border-purple-500 text-white';
  if (active) return 'bg-[var(--color-primary)] border-[var(--color-primary)] text-white';
  return 'bg-white border-slate-100 text-slate-200 border-dashed hover:border-slate-300';
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

function toggleSeatById(seatId) {
  if (!seatId) return;
  const match = seatId.match(/^([A-Za-z]+)(\d+)$/);
  if (match) toggleSeat(match[1].toUpperCase(), parseInt(match[2]));
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

function clearAll() {
  if (confirm('ล้างที่นั่งทั้งหมด?')) {
    layout.value.seats = [];
  }
}

function resetToVan() {
  if (confirm('คุณต้องการรีเซ็ตเป็นรูปแบบรถตู้ VIP มาตรฐานใช่หรือไม่?')) {
    layout.value.rows = 4;
    columnsRaw.value = "A,B,C,,D,E";
    layout.value.columns = ["A","B","C","","D","E"];
    layout.value.front_seat = "A1";
    layout.value.last_row_center = ["A4","B4","C4"];
    layout.value.front_label = "หน้ารถ";
    layout.value.rear_label = "ท้ายรถ (สำหรับเก็บสัมภาระ)";
    layout.value.driver_icon = "directions_car";
    layout.value.show_driver = true;
    frontSeatRaw.value = "A1";
    lastRowCenterRaw.value = "A4,B4,C4";
    layout.value.seats = [
      {id:'A1', row:1, col:'A', label:'A1'},
      {id:'A2', row:2, col:'A', label:'A2'}, {id:'D2', row:2, col:'D', label:'D2'}, {id:'E2', row:2, col:'E', label:'E2'},
      {id:'A3', row:3, col:'A', label:'A3'}, {id:'D3', row:3, col:'D', label:'D3'}, {id:'E3', row:3, col:'E', label:'E3'},
      {id:'A4', row:4, col:'A', label:'A4'}, {id:'B4', row:4, col:'B', label:'B4'}, {id:'C4', row:4, col:'C', label:'C4'},
    ];
  }
}

/// รถบัส 2 + ทางเดิน + 2 ทุกแถว ปิดท้ายด้วยแถวหลังนั่งยาว 5 ที่ — รูปแบบเดียว
/// กับผังตั้งต้นที่เซิร์ฟเวอร์ปั้นให้ (App\Support\SeatLayoutFactory)
function resetToBus() {
  const rows = parseInt(prompt('รถบัสคันนี้มีกี่แถว (รวมแถวหลังที่นั่งยาว)?', '11'), 10);
  if (!rows || rows < 2 || rows > 15) return;

  layout.value.rows = rows;
  columnsRaw.value = 'A,B,,C,D,E';
  layout.value.columns = ['A', 'B', '', 'C', 'D', 'E'];
  layout.value.front_seat = null;
  layout.value.front_label = 'หน้ารถ';
  layout.value.rear_label = 'ท้ายรถ (ห้องน้ำ / สัมภาระ)';
  layout.value.driver_icon = 'directions_bus';
  layout.value.show_driver = true;
  frontSeatRaw.value = '';

  const seats = [];
  for (let r = 1; r < rows; r++) {
    for (const col of ['A', 'B', 'C', 'D']) {
      seats.push({ id: `${col}${r}`, row: r, col, label: `${col}${r}` });
    }
  }
  const back = ['A', 'B', 'C', 'D', 'E'].map(col => `${col}${rows}`);
  for (const id of back) {
    seats.push({ id, row: rows, col: id[0], label: id });
  }
  layout.value.seats = seats;
  layout.value.last_row_center = back;
  lastRowCenterRaw.value = back.join(',');
}

onMounted(() => {
  // Ensure default structure if missing
  if (!layout.value.columns) layout.value.columns = ["A","B","C","","D","E"];
  if (!layout.value.seats) layout.value.seats = [];
  if (layout.value.front_seat === undefined) layout.value.front_seat = null;
  if (!layout.value.last_row_center) layout.value.last_row_center = [];
  if (!layout.value.front_label) layout.value.front_label = 'หน้ารถ';
  if (!layout.value.rear_label) layout.value.rear_label = 'ท้ายรถ (สำหรับเก็บสัมภาระ)';
  if (!layout.value.driver_icon) layout.value.driver_icon = 'directions_car';
  if (layout.value.show_driver === undefined) layout.value.show_driver = true;
  columnsRaw.value = layout.value.columns.join(',');
  frontSeatRaw.value = layout.value.front_seat || '';
  lastRowCenterRaw.value = (layout.value.last_row_center || []).join(',');
});
</script>

<style scoped>
.vehicle-shell {
  background-image: 
    radial-gradient(circle at 10px 10px, rgba(0,0,0,0.02) 1px, transparent 0);
  background-size: 20px 20px;
}
</style>
