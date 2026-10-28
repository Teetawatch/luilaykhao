<template>
  <!-- ช่องเลือกยานพาหนะที่พิมพ์ค้นหาได้ — รถในระบบเยอะจน <select> เลื่อนหาไม่ไหว
       ค้นจากชื่อ ทะเบียน และชื่อคนขับ (แบบเดียวกับช่องค้นหาของหน้ายานพาหนะ) -->
  <div class="vs" :class="[`vs--${size}`, { 'vs--open': open }]" ref="root">
    <div class="vs-control">
      <span class="material-symbols-rounded vs-lead">{{ selected ? vehicleTypeIcon(selected.type) : 'search' }}</span>
      <input
        ref="input"
        v-model="query"
        type="text"
        role="combobox"
        autocomplete="off"
        :aria-expanded="open"
        :placeholder="selected ? selectedLabel : `${emptyLabel} — พิมพ์ชื่อ ทะเบียน หรือคนขับ`"
        :class="{ 'has-value': selected }"
        @focus="openList"
        @click="openList"
        @input="onInput"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter.prevent="pickHighlighted"
        @keydown.esc="close"
        @keydown.tab="close"
      />
      <button
        v-if="modelValue !== null && modelValue !== undefined && modelValue !== ''"
        type="button"
        class="vs-clear"
        title="ล้างค่า"
        @mousedown.prevent="pick(null)"
      >
        <span class="material-symbols-rounded">close</span>
      </button>
      <span v-else class="material-symbols-rounded vs-caret">expand_more</span>
    </div>

    <ul class="vs-list" v-if="open" role="listbox">
      <li
        class="vs-item vs-item--empty"
        :class="{ 'is-active': highlight === -1, 'is-selected': !selected }"
        @mousedown.prevent="pick(null)"
        @mouseenter="highlight = -1"
      >
        {{ emptyLabel }}
      </li>
      <li
        v-for="(v, i) in matches"
        :key="v.id"
        class="vs-item"
        :class="{ 'is-active': highlight === i, 'is-selected': v.id === modelValue }"
        role="option"
        :aria-selected="v.id === modelValue"
        @mousedown.prevent="pick(v.id)"
        @mouseenter="highlight = i"
      >
        <span class="material-symbols-rounded vs-item-icon">{{ vehicleTypeIcon(v.type) }}</span>
        <span class="vs-item-main">
          <span class="vs-item-name">{{ v.name }}</span>
          <span class="vs-item-meta">
            {{ vehicleTypeLabel(v.type) }}<template v-if="v.capacity"> · {{ v.capacity }} ที่</template>
            <template v-if="v.license_plate"> · {{ v.license_plate }}</template>
            <template v-if="v.driver_name"> · {{ v.driver_name }}</template>
          </span>
        </span>
        <span class="vs-item-tag" v-if="USAGE_TAGS[v.usage_status]">{{ USAGE_TAGS[v.usage_status] }}</span>
      </li>
      <li class="vs-none" v-if="!matches.length">ไม่พบรถที่ตรงกับ “{{ query }}”</li>
    </ul>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { vehicleTypeIcon, vehicleTypeLabel } from '../lib/vehicleDisplay';

const props = defineProps({
  modelValue: { type: [Number, String, null], default: null },
  vehicles: { type: Array, default: () => [] },
  // ข้อความของตัวเลือก "ไม่ระบุรถ" (null) — แต่ละช่องเรียกต่างกัน
  emptyLabel: { type: String, default: 'ยังไม่ระบุ' },
  size: { type: String, default: 'md' }, // 'md' | 'sm'
});
const emit = defineEmits(['update:modelValue']);

// ป้ายสถานะการใช้งาน (VehicleResource.usage_status) — ช่วยไม่ให้หยิบรถเก่าที่เลิกวิ่งมาใส่โดยไม่ตั้งใจ
const USAGE_TAGS = { never_used: 'ยังไม่เคยใช้', past: 'ใช้ผ่านมาแล้ว' };

const root = ref(null);
const input = ref(null);
const open = ref(false);
const query = ref('');
const highlight = ref(-1);

const selected = computed(() => props.vehicles.find(v => v.id === props.modelValue) || null);
const selectedLabel = computed(() => {
  const v = selected.value;
  if (!v) return '';
  return v.license_plate ? `${v.name} · ${v.license_plate}` : v.name;
});

// รถที่ยังวิ่งอยู่ขึ้นก่อน รถที่ไม่เคยใช้/เลิกวิ่งแล้วไปท้ายรายการ (ในกลุ่มเดียวกันคงลำดับชื่อเดิม)
const USAGE_RANK = { active: 0, never_used: 1, past: 2 };
const ordered = computed(() => [...props.vehicles].sort(
  (a, b) => (USAGE_RANK[a.usage_status] ?? 0) - (USAGE_RANK[b.usage_status] ?? 0),
));

const matches = computed(() => {
  const q = query.value.trim().toLowerCase();
  if (!q) return ordered.value;
  return ordered.value.filter(v =>
    (v.name || '').toLowerCase().includes(q) ||
    (v.license_plate || '').toLowerCase().includes(q) ||
    (v.driver_name || '').toLowerCase().includes(q)
  );
});

// ตอนปิดอยู่ ช่องแสดงชื่อรถที่เลือก (ผ่าน placeholder) — เปิดแล้วพิมพ์ทับได้ทันทีโดยไม่ต้องลบชื่อเดิม
const openList = () => {
  if (open.value) return;
  open.value = true;
  query.value = '';
  const idx = matches.value.findIndex(v => v.id === props.modelValue);
  highlight.value = idx;
  nextTick(scrollToHighlight);
};

const close = () => {
  open.value = false;
  query.value = '';
};

const onInput = () => {
  if (!open.value) open.value = true;
  highlight.value = matches.value.length ? 0 : -1;
};

const pick = (id) => {
  emit('update:modelValue', id);
  close();
  input.value?.blur();
};

const pickHighlighted = () => {
  if (!open.value) return openList();
  // พิมพ์แล้วไม่เจอรถ — Enter ต้องไม่ไปล้างรถที่เลือกไว้เดิม
  if (highlight.value === -1) return query.value.trim() ? undefined : pick(null);
  const v = matches.value[highlight.value];
  if (v) pick(v.id);
};

const move = (step) => {
  if (!open.value) return openList();
  // -1 คือแถว "ไม่ระบุรถ" ด้านบนสุด
  const max = matches.value.length - 1;
  highlight.value = Math.min(max, Math.max(-1, highlight.value + step));
  nextTick(scrollToHighlight);
};

const scrollToHighlight = () => {
  root.value?.querySelector('.vs-item.is-active')?.scrollIntoView({ block: 'nearest' });
};

const onDocPointer = (e) => {
  if (open.value && root.value && !root.value.contains(e.target)) close();
};
onMounted(() => document.addEventListener('mousedown', onDocPointer));
onUnmounted(() => document.removeEventListener('mousedown', onDocPointer));
</script>

<style scoped>
.vs { position: relative; width: 100%; }

.vs-control { position: relative; display: flex; align-items: center; }
.vs-control input {
  width: 100%;
  padding: 9px 34px 9px 36px;
  background: #ffffff;
  border: 1px solid #d1d5db;
  border-radius: 8px;
  color: #111827;
  font-size: 14px;
  outline: none;
  transition: border-color 0.15s;
  font-family: 'DB Heavent', 'Anuphan', sans-serif;
}
.vs-control input:focus {
  border-color: #2d7a4f;
  box-shadow: 0 0 0 3px rgba(45, 122, 79, 0.08);
}
.vs-control input::placeholder { color: #9ca3af; }
/* ปิดอยู่และเลือกรถไว้แล้ว — ชื่อรถอยู่ใน placeholder จึงต้องเข้มเท่าค่าจริง */
.vs:not(.vs--open) .vs-control input.has-value::placeholder { color: #111827; }

.vs-lead {
  position: absolute; left: 10px;
  font-size: 18px; color: #6b7280; pointer-events: none;
}
.vs-caret, .vs-clear {
  position: absolute; right: 8px;
  font-size: 18px; color: #9ca3af;
}
.vs-caret { pointer-events: none; }
.vs-clear {
  display: flex; align-items: center; justify-content: center;
  width: 22px; height: 22px; padding: 0;
  border: none; border-radius: 50%; background: transparent; cursor: pointer;
}
.vs-clear .material-symbols-rounded { font-size: 16px; }
.vs-clear:hover { background: #f3f4f6; color: #374151; }

.vs-list {
  position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 30;
  max-height: 280px; overflow-y: auto;
  margin: 0; padding: 4px; list-style: none;
  background: #ffffff;
  border: 1px solid #d1d5db; border-radius: 10px;
  box-shadow: 0 10px 24px rgba(17, 24, 39, 0.12);
}
.vs-item {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 10px; border-radius: 8px; cursor: pointer;
}
.vs-item.is-active { background: #f0f7f3; }
.vs-item.is-selected .vs-item-name,
.vs-item--empty.is-selected { color: #2d7a4f; font-weight: 700; }
.vs-item--empty { font-size: 13px; color: #6b7280; }
.vs-item-icon { font-size: 18px; color: #6b7280; flex-shrink: 0; }
.vs-item-main { display: flex; flex-direction: column; min-width: 0; flex: 1; }
.vs-item-name { font-size: 14px; font-weight: 600; color: #111827; }
.vs-item-meta {
  font-size: 12px; color: #6b7280;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.vs-item-tag {
  flex-shrink: 0;
  font-size: 11px; font-weight: 600; color: #6b7280;
  background: #f3f4f6; border-radius: 999px; padding: 2px 8px;
}
.vs-none { padding: 10px; font-size: 13px; color: #9ca3af; text-align: center; }

/* ขนาดเล็กสำหรับช่องในแถวตัวเลือกยานพาหนะ */
.vs--sm .vs-control input {
  padding: 7px 30px 7px 30px;
  border-color: var(--color-border, #e5e7eb);
  font-size: 13px;
  font-family: inherit;
}
.vs--sm .vs-lead { left: 8px; font-size: 16px; }
.vs--sm .vs-list { min-width: 260px; }
</style>
