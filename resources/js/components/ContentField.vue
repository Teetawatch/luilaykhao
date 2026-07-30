<template>
  <!-- ข้อความบรรทัดเดียว / ไอคอน -->
  <div v-if="field.type === 'text' || field.type === 'icon'" class="form-group full-width">
    <label>{{ field.label }}</label>
    <input :value="modelValue ?? ''" @input="emitValue($event.target.value)" />
    <p v-if="hint" class="cf-hint">{{ hint }}</p>
  </div>

  <div v-else-if="field.type === 'textarea'" class="form-group full-width">
    <label>{{ field.label }}</label>
    <textarea :value="modelValue ?? ''" rows="3" @input="emitValue($event.target.value)"></textarea>
    <p v-if="hint" class="cf-hint">{{ hint }}</p>
  </div>

  <div v-else-if="field.type === 'color'" class="form-group full-width">
    <label>{{ field.label }}</label>
    <div class="cf-color">
      <input type="color" :value="modelValue || '#666666'" @input="emitValue($event.target.value)" />
      <input class="cf-color-text" :value="modelValue ?? ''" placeholder="#66C291" @input="emitValue($event.target.value)" />
    </div>
    <p v-if="hint" class="cf-hint">{{ hint }}</p>
  </div>

  <div v-else-if="field.type === 'bool'" class="form-group full-width">
    <label class="cf-check">
      <input type="checkbox" :checked="!!modelValue" @change="emitValue($event.target.checked)" />
      {{ field.label }}
    </label>
    <p v-if="hint" class="cf-hint">{{ hint }}</p>
  </div>

  <div v-else-if="field.type === 'select'" class="form-group full-width">
    <label>{{ field.label }}</label>
    <select :value="modelValue ?? ''" @change="emitValue($event.target.value)">
      <option v-for="(label, key) in field.options" :key="key" :value="key">{{ label }}</option>
    </select>
    <p v-if="hint" class="cf-hint">{{ hint }}</p>
  </div>

  <!-- รายการข้อความ: กรอกบรรทัดละข้อ อ่านง่ายกว่าปุ่มเพิ่มทีละแถว -->
  <div v-else-if="field.type === 'list'" class="form-group full-width">
    <label>{{ field.label }} <span class="cf-sub">(บรรทัดละ 1 ข้อ)</span></label>
    <textarea :value="(modelValue || []).join('\n')" rows="5" @input="emitLines($event.target.value)"></textarea>
    <p v-if="hint" class="cf-hint">{{ hint }}</p>
  </div>

  <!-- เลือกได้หลายค่า โดยตัวเลือกมาจาก repeater อีกช่องในหน้าเดียวกัน -->
  <div v-else-if="field.type === 'multiselect'" class="form-group full-width">
    <label>{{ field.label }}</label>
    <div class="cf-chips">
      <button
        v-for="option in optionsFromRoot"
        :key="option.key"
        type="button"
        class="cf-chip"
        :class="{ 'cf-chip--on': (modelValue || []).includes(option.key) }"
        @click="toggleOption(option.key)"
      >{{ option.label || option.key }}</button>
      <span v-if="!optionsFromRoot.length" class="cf-hint">ยังไม่มีตัวเลือกให้เลือก</span>
    </div>
    <p v-if="hint" class="cf-hint">{{ hint }}</p>
  </div>

  <!-- กลุ่มช่องที่ซ้ำได้ — เพิ่ม/ลบ/เลื่อนขึ้นลง -->
  <div v-else-if="field.type === 'repeater'" class="form-group full-width">
    <div class="cf-rep-head">
      <label>{{ field.label }}</label>
      <button type="button" class="cf-add" @click="addRow">
        <span class="material-symbols-rounded">add</span> เพิ่ม{{ field.item_label || 'รายการ' }}
      </button>
    </div>
    <p v-if="hint" class="cf-hint cf-hint--above">{{ hint }}</p>

    <div v-if="!rows.length" class="cf-empty">
      ยังไม่มี{{ field.item_label || 'รายการ' }} — กด "เพิ่ม{{ field.item_label || 'รายการ' }}" เพื่อเริ่ม
    </div>

    <div v-for="(row, index) in rows" :key="index" class="cf-row">
      <div class="cf-row-head">
        <span class="cf-row-title">
          {{ field.item_label || 'รายการ' }} {{ index + 1 }}
          <em v-if="rowSummary(row)">— {{ rowSummary(row) }}</em>
        </span>
        <div class="cf-row-actions">
          <button type="button" title="เลื่อนขึ้น" :disabled="index === 0" @click="move(index, -1)">
            <span class="material-symbols-rounded">arrow_upward</span>
          </button>
          <button type="button" title="เลื่อนลง" :disabled="index === rows.length - 1" @click="move(index, 1)">
            <span class="material-symbols-rounded">arrow_downward</span>
          </button>
          <button type="button" class="cf-del" title="ลบ" @click="removeRow(index)">
            <span class="material-symbols-rounded">delete</span>
          </button>
        </div>
      </div>

      <div class="cf-row-body">
        <ContentField
          v-for="sub in field.fields"
          :key="sub.key"
          :field="sub"
          :model-value="row[sub.key]"
          :root="root"
          @update:model-value="setRowField(index, sub.key, $event)"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * วาดช่องกรอกหนึ่งช่องตาม schema ที่หลังบ้านส่งมา และเรียกตัวเองซ้ำสำหรับ repeater
 * ทำแบบ generic เพื่อให้เพิ่มช่องใหม่ใน PageContent.php แล้วหน้าแอดมินขึ้นเองโดยไม่ต้องแก้ที่นี่
 */
import { computed } from 'vue';

const props = defineProps({
  field: { type: Object, required: true },
  modelValue: { type: null, default: undefined },
  /** เนื้อหาทั้งหน้า — ใช้หา options ของ multiselect ที่อ้างถึง repeater ช่องอื่น */
  root: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:model-value']);

const hint = computed(() => props.field.hint || '');

const rows = computed(() => (Array.isArray(props.modelValue) ? props.modelValue : []));

const optionsFromRoot = computed(() => {
  const source = props.root?.[props.field.options_from];

  return Array.isArray(source) ? source.filter(o => o && o.key) : [];
});

function emitValue(value) {
  emit('update:model-value', value);
}

function emitLines(text) {
  emit('update:model-value', text.split('\n').map(l => l.trim()).filter(Boolean));
}

function toggleOption(key) {
  const current = Array.isArray(props.modelValue) ? props.modelValue : [];

  emit('update:model-value', current.includes(key)
    ? current.filter(k => k !== key)
    : [...current, key]);
}

/** แถวใหม่ต้องมีคีย์ครบทุกช่องตั้งแต่แรก ไม่งั้น validate ฝั่ง Laravel จะตกที่กฎ present */
function blankRow() {
  const row = {};

  for (const sub of props.field.fields || []) {
    row[sub.key] = {
      bool: false,
      list: [],
      multiselect: [],
      repeater: [],
    }[sub.type] ?? (sub.type === 'select' ? Object.keys(sub.options || {})[0] || '' : '');
  }

  return row;
}

function addRow() {
  emit('update:model-value', [...rows.value, blankRow()]);
}

function removeRow(index) {
  emit('update:model-value', rows.value.filter((_, i) => i !== index));
}

function move(index, delta) {
  const next = [...rows.value];
  const target = index + delta;

  if (target < 0 || target >= next.length) return;

  [next[index], next[target]] = [next[target], next[index]];
  emit('update:model-value', next);
}

function setRowField(index, key, value) {
  emit('update:model-value', rows.value.map(
    (row, i) => (i === index ? { ...row, [key]: value } : row),
  ));
}

/** ข้อความย่อบนหัวแถว เพื่อให้พับดูแล้วรู้ว่าแถวไหนคืออะไร */
function rowSummary(row) {
  for (const key of ['title', 'label', 'name', 'q', 'key']) {
    if (typeof row?.[key] === 'string' && row[key].trim()) {
      return row[key].length > 48 ? `${row[key].slice(0, 48)}…` : row[key];
    }
  }

  return '';
}
</script>

<style scoped>
.cf-hint {
  font-size: 12px;
  color: #9ca3af;
  margin-top: 4px;
}

.cf-hint--above {
  margin: -4px 0 8px;
}

.cf-sub {
  font-weight: 400;
  color: #9ca3af;
}

.cf-check {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
}

.cf-check input {
  width: 16px;
  height: 16px;
}

.cf-color {
  display: flex;
  gap: 8px;
  align-items: center;
}

.cf-color input[type='color'] {
  width: 44px;
  height: 38px;
  padding: 2px;
  cursor: pointer;
}

.cf-color-text {
  flex: 1;
}

.cf-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.cf-chip {
  border: 1px solid #e5e7eb;
  background: #fff;
  border-radius: 999px;
  padding: 5px 12px;
  font-size: 12px;
  font-weight: 700;
  color: #6b7280;
  cursor: pointer;
}

.cf-chip--on {
  background: #006565;
  border-color: #006565;
  color: #fff;
}

.cf-rep-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 6px;
}

.cf-add {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border: 1px solid #006565;
  background: #fff;
  color: #006565;
  border-radius: 10px;
  padding: 5px 12px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
}

.cf-add .material-symbols-rounded {
  font-size: 16px;
}

.cf-empty {
  border: 1px dashed #e5e7eb;
  border-radius: 12px;
  padding: 16px;
  text-align: center;
  font-size: 13px;
  color: #9ca3af;
}

.cf-row {
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  margin-bottom: 10px;
  background: #fcfdfd;
}

.cf-row-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 10px 8px 14px;
  border-bottom: 1px solid #eef2f2;
}

.cf-row-title {
  font-size: 12px;
  font-weight: 700;
  color: #6b7280;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cf-row-title em {
  font-style: normal;
  font-weight: 400;
  color: #9ca3af;
}

.cf-row-actions {
  display: flex;
  gap: 2px;
  flex-shrink: 0;
}

.cf-row-actions button {
  border: none;
  background: transparent;
  color: #9ca3af;
  cursor: pointer;
  padding: 4px;
  border-radius: 8px;
  display: inline-flex;
}

.cf-row-actions button:disabled {
  opacity: 0.3;
  cursor: default;
}

.cf-row-actions button:not(:disabled):hover {
  background: #f1f5f5;
  color: #374151;
}

.cf-row-actions .cf-del:hover {
  background: #fee4e2;
  color: #b42318;
}

.cf-row-actions .material-symbols-rounded {
  font-size: 18px;
}

.cf-row-body {
  padding: 12px 14px 4px;
  display: grid;
  gap: 12px;
}
</style>
