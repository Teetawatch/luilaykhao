<template>
  <div class="admin-page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><i class="fas fa-campground pg-title-icon"></i> เกมรอบวง</h1>
        <p class="page-subtitle">คลังเกมตอนเย็นที่ลูกทริปและสตาฟเปิดเล่นในแอประหว่างทริป — เล่นเครื่องเดียววนส่งกัน ไม่ต้องมีสัญญาณ</p>
      </div>
      <div class="pg-head-actions">
        <button v-if="bulkGames.includes(game)" class="btn-secondary" @click="openBulk">
          <i class="fas fa-paste"></i> วางหลายบรรทัด
        </button>
        <button class="btn-primary" @click="openCreate">
          <i class="fas fa-plus"></i> เพิ่มใบใหม่
        </button>
      </div>
    </div>

    <div class="pg-tips">
      <i class="fas fa-lightbulb"></i>
      <div>
        แอปดึงคลังนี้ไปเก็บในเครื่องตั้งแต่ <strong>2 วันก่อนเดินทาง</strong> และเปิดเกมได้เฉพาะ
        <strong>วันที่รถออกจนถึงวันกลับ</strong> · แก้แล้วเครื่องที่ดึงไปก่อนจะได้ของใหม่ตอนมีสัญญาณครั้งถัดไป ·
        ใบที่ผูกกับทริปจะโผล่เฉพาะรอบของทริปนั้น (เช่น ควิซเรื่องภูกระดึง) ·
        เกม <strong>มนุษย์หมาป่า</strong> ไม่ใช้คลัง บทบาทอยู่ในแอปแล้ว ·
        <strong>คำต้องห้าม</strong> แจกคนละคำ ระดับ "โหด" คือคำติดปากที่เผลอพูดง่ายที่สุด ·
        <strong>ใครน่าจะ</strong> ใส่แค่ท้ายประโยค แอปขึ้นต้น "ใครน่าจะ…" ให้เอง ·
        <strong>อันไหนดี / คลื่นความคิด</strong> วางหลายบรรทัดแบบ <code>ซ้าย | ขวา</code> ได้
      </div>
    </div>

    <div class="pg-tabs">
      <button
        v-for="(meta, key) in GAMES"
        :key="key"
        class="pg-tab"
        :class="{ active: game === key }"
        @click="setGame(key)"
      >
        <i class="fas" :class="meta.icon"></i>
        {{ meta.label }}
        <span class="pg-count">{{ countFor(key) }}</span>
      </button>
    </div>

    <div class="filters-bar">
      <div class="search-box">
        <i class="fas fa-search pg-search-icon"></i>
        <input v-model="search" type="text" placeholder="ค้นหาข้อความ" />
      </div>
      <select v-if="game === 'truth_dare'" v-model="filterKind">
        <option value="">ความจริง + ท้า</option>
        <option v-for="(label, key) in KINDS" :key="key" :value="key">{{ label }}</option>
      </select>
      <select v-if="usesLevel(game)" v-model="filterLevel">
        <option value="">ทุกระดับ</option>
        <option v-for="(label, key) in levelsFor(game)" :key="key" :value="key">{{ label }}</option>
      </select>
      <select v-if="usesCategory(game)" v-model="filterCategory">
        <option value="">ทุกหมวด</option>
        <option v-for="c in categoriesFor(game)" :key="c" :value="c">{{ c }}</option>
      </select>
      <select v-model="filterTrip">
        <option value="">ทุกทริป</option>
        <option value="global">ใช้ได้ทุกทริป</option>
        <option v-for="t in tripsInUse" :key="t.id" :value="String(t.id)">{{ t.title }}</option>
      </select>
    </div>

    <div class="loading-state" v-if="loading"><div class="spinner"></div></div>

    <div v-else-if="!visible.length" class="table-card pg-empty">
      <i class="fas" :class="GAMES[game].icon"></i>
      <p>{{ cards.some((c) => c.game === game) ? 'ไม่มีใบที่ตรงกับตัวกรอง' : 'ยังไม่มีใบในเกมนี้ — กด "เพิ่มใบใหม่"' }}</p>
    </div>

    <div v-else class="table-card pg-list">
      <p class="pg-summary">
        {{ visible.length }} ใบ<span v-if="hiddenCount"> · ซ่อนอยู่ {{ hiddenCount }}</span>
      </p>
      <div v-for="card in visible" :key="card.id" class="pg-row" :class="{ 'pg-off': !card.is_active }">
        <div class="pg-main">
          <div class="pg-chips">
            <span v-if="card.kind" class="pg-chip" :class="`kind-${card.kind}`">{{ KINDS[card.kind] }}</span>
            <span v-if="card.level" class="pg-chip" :class="`level-${card.level}`">{{ levelsFor(card.game)[card.level] }}</span>
            <span v-if="card.category" class="pg-chip">{{ card.category }}</span>
            <span v-if="card.trip_id" class="pg-chip pg-chip-trip"><i class="fas fa-mountain"></i> {{ card.trip_title || 'ทริป' }}</span>
            <span v-if="!card.is_active" class="pg-chip pg-chip-off">ซ่อนอยู่</span>
          </div>
          <p class="pg-body">{{ card.game === 'most_likely' ? `ใครน่าจะ${card.body}` : card.body }}</p>

          <p v-if="card.game === 'spyfall'" class="pg-detail">
            <span class="pg-detail-label">บทบาท:</span> {{ (card.data.roles || []).join(' · ') }}
          </p>
          <p v-if="card.game === 'would_rather'" class="pg-detail">
            <span class="pg-detail-label">หรือ</span> {{ card.data.other }}
          </p>
          <p v-if="card.game === 'wavelength'" class="pg-detail">
            <i class="fas fa-arrows-left-right"></i> {{ card.data.right }}
          </p>
          <p v-if="card.game === 'heads_up' && card.data.taboo?.length" class="pg-detail">
            <span class="pg-detail-label">ห้ามพูด:</span> {{ card.data.taboo.join(' · ') }}
          </p>
          <template v-if="card.game === 'trivia'">
            <ol class="pg-choices">
              <li v-for="(choice, i) in card.data.choices || []" :key="i" :class="{ correct: i === card.data.answer }">
                <i v-if="i === card.data.answer" class="fas fa-check"></i> {{ choice }}
              </li>
            </ol>
            <p v-if="card.data.note" class="pg-detail"><span class="pg-detail-label">เฉลย:</span> {{ card.data.note }}</p>
          </template>
        </div>
        <div class="action-btns">
          <button class="btn-icon" :title="card.is_active ? 'ซ่อนจากแอป' : 'เปิดในแอป'" @click="toggle(card)">
            <i class="fas" :class="card.is_active ? 'fa-eye-slash' : 'fa-eye'"></i>
          </button>
          <button class="btn-icon btn-edit" title="แก้ไข" @click="openEdit(card)">
            <i class="fas fa-pen"></i>
          </button>
          <button class="btn-icon btn-delete" title="ลบ" @click="remove(card)">
            <i class="fas fa-trash"></i>
          </button>
        </div>
      </div>
    </div>

    <!-- เพิ่ม / แก้ไขหนึ่งใบ -->
    <div v-if="editor" class="modal-overlay" @click.self="editor = null">
      <div class="modal-card" style="max-width: 560px">
        <div class="modal-header">
          <h2>{{ editor.id ? 'แก้ไขใบ' : 'เพิ่มใบใหม่' }}</h2>
          <button class="modal-close" @click="editor = null"><i class="fas fa-xmark"></i></button>
        </div>
        <form @submit.prevent="save">
          <div class="modal-body">
            <div class="form-grid">
              <div class="form-group">
                <label>เกม</label>
                <select v-model="form.game">
                  <option v-for="(meta, key) in GAMES" :key="key" :value="key">{{ meta.label }}</option>
                </select>
              </div>
              <div v-if="form.game === 'truth_dare'" class="form-group">
                <label>ประเภท</label>
                <select v-model="form.kind">
                  <option v-for="(label, key) in KINDS" :key="key" :value="key">{{ label }}</option>
                </select>
              </div>
              <div v-if="usesLevel(form.game)" class="form-group">
                <label>ระดับความแรง</label>
                <select v-model="form.level">
                  <option v-for="(label, key) in levelsFor(form.game)" :key="key" :value="key">{{ label }}</option>
                </select>
              </div>
              <div v-if="usesCategory(form.game)" class="form-group">
                <label>หมวด</label>
                <input v-model="form.category" maxlength="60" list="pg-categories" required placeholder="เช่น สายแคมป์ปิ้ง" />
                <datalist id="pg-categories">
                  <option v-for="c in categoriesFor(form.game)" :key="c" :value="c" />
                </datalist>
              </div>
            </div>

            <div class="form-group">
              <label>{{ GAMES[form.game].bodyLabel }}</label>
              <div v-if="form.game === 'most_likely'" class="pg-prefixed">
                <span>ใครน่าจะ…</span>
                <input v-model="form.body" maxlength="80" required :placeholder="GAMES[form.game].placeholder" />
              </div>
              <input v-else-if="pairKey(form.game) || form.game === 'bomb'" v-model="form.body" maxlength="80" required :placeholder="GAMES[form.game].placeholder" />
              <textarea v-else v-model="form.body" rows="2" maxlength="500" required :placeholder="GAMES[form.game].placeholder"></textarea>
            </div>

            <div v-if="pairKey(form.game)" class="form-group">
              <label>{{ GAMES[form.game].pairLabel }}</label>
              <input v-model="form.pair" maxlength="80" required :placeholder="GAMES[form.game].pairPlaceholder" />
            </div>

            <div v-if="form.game === 'spyfall'" class="form-group">
              <label>บทบาทของคนที่ไม่ใช่สายลับ <span class="pg-optional">(บรรทัดละบทบาท อย่างน้อย 3)</span></label>
              <textarea v-model="form.roles" rows="5" placeholder="เจ้าหน้าที่อุทยาน&#10;ช่างภาพ&#10;คนขายกาแฟ"></textarea>
            </div>

            <div v-if="form.game === 'heads_up'" class="form-group">
              <label>คำต้องห้าม <span class="pg-optional">(ไม่บังคับ ใช้ในโหมด "ห้ามพูด" ไม่เกิน 5 คำ คั่นด้วยจุลภาค)</span></label>
              <input v-model="form.taboo" placeholder="เช่น นอน, กาง, ผ้าใบ" />
            </div>

            <template v-if="form.game === 'trivia'">
              <div class="form-group">
                <label>ตัวเลือก <span class="pg-optional">(2–4 ข้อ กดวงกลมหน้าข้อที่ถูก)</span></label>
                <div v-for="(c, i) in form.choices" :key="i" class="pg-choice-input">
                  <input v-model="form.answer" type="radio" :value="i" :disabled="!c.trim()" title="ข้อที่ถูก" />
                  <input v-model="form.choices[i]" maxlength="80" :placeholder="`ตัวเลือกที่ ${i + 1}`" />
                </div>
              </div>
              <div class="form-group">
                <label>คำอธิบายหลังเฉลย <span class="pg-optional">(ไม่บังคับ)</span></label>
                <input v-model="form.note" maxlength="300" placeholder="เช่น สูงราว 2,565 เมตร" />
              </div>
            </template>

            <div class="form-group">
              <label>ใช้กับ</label>
              <select v-model="form.trip_id">
                <option :value="null">ทุกทริป</option>
                <option v-for="t in trips" :key="t.id" :value="t.id">เฉพาะ {{ t.title }}</option>
              </select>
            </div>
            <label class="pg-check">
              <input v-model="form.is_active" type="checkbox" /> เปิดให้ใช้ในแอป
            </label>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="editor = null">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="saving">
              <i v-if="saving" class="fas fa-spinner fa-spin"></i> บันทึก
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- วางหลายบรรทัด -->
    <div v-if="bulk" class="modal-overlay" @click.self="bulk = null">
      <div class="modal-card" style="max-width: 560px">
        <div class="modal-header">
          <h2>วางหลายบรรทัด · {{ GAMES[bulk.game].label }}</h2>
          <button class="modal-close" @click="bulk = null"><i class="fas fa-xmark"></i></button>
        </div>
        <form @submit.prevent="saveBulk">
          <div class="modal-body">
            <div class="form-grid">
              <div v-if="bulk.game === 'truth_dare'" class="form-group">
                <label>ประเภท</label>
                <select v-model="bulk.kind">
                  <option v-for="(label, key) in KINDS" :key="key" :value="key">{{ label }}</option>
                </select>
              </div>
              <div v-if="usesLevel(bulk.game)" class="form-group">
                <label>ระดับความแรง</label>
                <select v-model="bulk.level">
                  <option v-for="(label, key) in levelsFor(bulk.game)" :key="key" :value="key">{{ label }}</option>
                </select>
              </div>
              <div v-if="bulk.game === 'heads_up'" class="form-group">
                <label>หมวด</label>
                <input v-model="bulk.category" maxlength="60" list="pg-categories-bulk" required />
                <datalist id="pg-categories-bulk">
                  <option v-for="c in categoriesFor('heads_up')" :key="c" :value="c" />
                </datalist>
              </div>
              <div class="form-group">
                <label>ใช้กับ</label>
                <select v-model="bulk.trip_id">
                  <option :value="null">ทุกทริป</option>
                  <option v-for="t in trips" :key="t.id" :value="t.id">เฉพาะ {{ t.title }}</option>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label>หนึ่งบรรทัด = หนึ่งใบ <span class="pg-optional">({{ bulkLineCount }} บรรทัด · สูงสุด 200)</span></label>
              <textarea v-model="bulk.lines" rows="10" :placeholder="GAMES[bulk.game].placeholder"></textarea>
            </div>
            <p v-if="pairKey(bulk.game)" class="pg-hint">บรรทัดละคู่ คั่นสองฝั่งด้วย <code>|</code> เช่น <code>{{ GAMES[bulk.game].bulkExample }}</code></p>
            <p v-if="bulk.game === 'most_likely'" class="pg-hint">ใส่แค่ท้ายประโยค แอปขึ้นต้น "ใครน่าจะ…" ให้เอง (พิมพ์มาด้วยก็ถูกตัดออกให้)</p>
            <p v-if="bulk.game === 'heads_up'" class="pg-hint">คำที่วางแบบนี้ยังไม่มีคำต้องห้าม — เล่นโหมดแปะหน้าผากได้เลย ถ้าจะใช้โหมด "ห้ามพูด" ค่อยกดแก้ใส่ทีหลัง</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn-secondary" @click="bulk = null">ยกเลิก</button>
            <button type="submit" class="btn-primary" :disabled="saving || !bulkLineCount">
              <i v-if="saving" class="fas fa-spinner fa-spin"></i> เพิ่ม {{ bulkLineCount }} ใบ
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import api from '../../lib/axios';
import { useToast } from '../../lib/toast';
import { useSwal } from '../../lib/swal';

// คลังเกมรอบวง — ดู PartyGameService (รูปร่าง data ต่อเกมตรวจที่ normalize())
// แอปดึงผ่าน GET party-games เฉพาะคนที่อยู่ในรอบที่ใกล้/กำลังเดินทาง

const toast = useToast();
const swal = useSwal();

const GAMES = {
  spyfall: { label: 'สายลับจับเท็จ', icon: 'fa-user-secret', bodyLabel: 'สถานที่', placeholder: 'เช่น ลานกางเต็นท์บนดอย' },
  truth_dare: { label: 'ความจริงหรือท้า', icon: 'fa-circle-question', bodyLabel: 'โจทย์', placeholder: 'เช่น เลียนเสียงสัตว์ป่า 3 ชนิดให้ทุกคนทาย' },
  never: { label: 'ฉันไม่เคย', icon: 'fa-hand', bodyLabel: 'ประโยค', placeholder: 'เช่น ฉันไม่เคยเห็นทะเลหมอก' },
  heads_up: { label: 'ใบ้คำ', icon: 'fa-face-grin-squint', bodyLabel: 'คำที่ต้องทาย', placeholder: 'เช่น ถุงนอน' },
  trivia: { label: 'ควิซ', icon: 'fa-lightbulb', bodyLabel: 'คำถาม', placeholder: 'เช่น ยอดเขาที่สูงที่สุดในไทยคือ' },
  forbidden: { label: 'คำต้องห้าม', icon: 'fa-comment-slash', bodyLabel: 'คำ (สั้น ๆ ไม่เกิน 30 ตัวอักษร)', placeholder: 'เช่น หนาว' },
  most_likely: { label: 'ใครน่าจะ', icon: 'fa-hand-point-right', bodyLabel: 'ท้ายประโยค', placeholder: 'เช่น หลงทางคนแรกในทริปนี้' },
  would_rather: {
    label: 'อันไหนดี',
    icon: 'fa-code-compare',
    bodyLabel: 'ตัวเลือกแรก',
    placeholder: 'เช่น ตื่นตีห้าไปดูทะเลหมอก',
    pairLabel: 'หรือ… (ตัวเลือกที่สอง)',
    pairPlaceholder: 'เช่น นอนต่อในถุงนอนอุ่น ๆ',
    bulkExample: 'ไปทะเลทุกทริป | ไปภูเขาทุกทริป',
  },
  bomb: { label: 'ระเบิดเวลา', icon: 'fa-bomb', bodyLabel: 'หัวข้อ (ตอบได้ทีละคำ)', placeholder: 'เช่น ยี่ห้อรถยนต์' },
  wavelength: {
    label: 'คลื่นความคิด',
    icon: 'fa-wave-square',
    bodyLabel: 'ปลายซ้ายของเส้น',
    placeholder: 'เช่น ไม่น่ากลัวเลย',
    pairLabel: 'ปลายขวาของเส้น',
    pairPlaceholder: 'เช่น น่ากลัวสุด ๆ',
    bulkExample: 'ของถูก | ของแพง',
  },
};
// เกมคู่ — ฝั่งที่สองเก็บใน data ตาม PartyGameCard::PAIR_GAMES
const PAIR_KEYS = { would_rather: 'other', wavelength: 'right' };
const pairKey = (g) => PAIR_KEYS[g] || null;
const KINDS = { truth: 'ความจริง', dare: 'ท้า' };
const LEVELS = { light: 'เบา ๆ', medium: 'กลาง ๆ', spicy: 'สนิทกันแล้ว' };
// คำต้องห้ามใช้ระดับชุดเดียวกัน แต่ความหมายคือ "เผลอพูดยากแค่ไหน"
const FORBIDDEN_LEVELS = { light: 'ง่าย · ต้องหลอกถึงจะพูด', medium: 'กลาง · คำในวงคุย', spicy: 'โหด · คำติดปาก' };
const levelsFor = (g) => (g === 'forbidden' ? FORBIDDEN_LEVELS : LEVELS);
const bulkGames = ['truth_dare', 'never', 'heads_up', 'forbidden', 'most_likely', 'would_rather', 'bomb', 'wavelength'];

const usesLevel = (g) => ['truth_dare', 'never', 'forbidden', 'most_likely', 'would_rather'].includes(g);
const usesCategory = (g) => g === 'heads_up' || g === 'trivia';

const cards = ref([]);
const trips = ref([]);
const loading = ref(true);
const saving = ref(false);

const game = ref('spyfall');
const search = ref('');
const filterKind = ref('');
const filterLevel = ref('');
const filterCategory = ref('');
const filterTrip = ref('');

const editor = ref(null);
const form = reactive(blankForm('spyfall'));
const bulk = ref(null);

function blankForm(g) {
  return {
    game: g,
    kind: 'truth',
    level: 'light',
    category: '',
    body: '',
    pair: '',
    roles: '',
    taboo: '',
    choices: ['', '', '', ''],
    answer: 0,
    note: '',
    trip_id: null,
    is_active: true,
  };
}

function errorText(e, fallback) {
  const errors = e.response?.data?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first.length) return first[0];
  }
  return e.response?.data?.message || fallback;
}

const countFor = (g) => cards.value.filter((c) => c.game === g && c.is_active).length;

function categoriesFor(g) {
  return [...new Set(cards.value.filter((c) => c.game === g && c.category).map((c) => c.category))].sort();
}

const tripsInUse = computed(() => {
  const ids = new Set(cards.value.filter((c) => c.trip_id).map((c) => c.trip_id));
  return trips.value.filter((t) => ids.has(t.id));
});

const inGame = computed(() => cards.value.filter((c) => c.game === game.value));

const visible = computed(() => {
  const q = search.value.trim().toLowerCase();
  return inGame.value.filter((c) => {
    if (filterKind.value && c.kind !== filterKind.value) return false;
    if (filterLevel.value && c.level !== filterLevel.value) return false;
    if (filterCategory.value && c.category !== filterCategory.value) return false;
    if (filterTrip.value === 'global' && c.trip_id) return false;
    if (filterTrip.value && filterTrip.value !== 'global' && String(c.trip_id) !== filterTrip.value) return false;
    if (!q) return true;
    const haystack = [c.body, c.category, c.data?.other, c.data?.right, ...(c.data?.roles || []), ...(c.data?.taboo || []), ...(c.data?.choices || [])]
      .join(' ')
      .toLowerCase();
    return haystack.includes(q);
  });
});

const hiddenCount = computed(() => visible.value.filter((c) => !c.is_active).length);

const bulkLineCount = computed(() => {
  if (!bulk.value) return 0;
  return new Set(bulk.value.lines.split(/\r?\n/).map((l) => l.trim()).filter(Boolean)).size;
});

function setGame(g) {
  game.value = g;
  filterKind.value = '';
  filterLevel.value = '';
  filterCategory.value = '';
}

watch(game, () => (search.value = ''));

async function load() {
  try {
    const res = await api.get('/admin/party-games');
    cards.value = res.data.data?.cards || [];
    trips.value = res.data.data?.trips || [];
  } catch (e) {
    toast.error(errorText(e, 'โหลดคลังเกมไม่สำเร็จ'));
  } finally {
    loading.value = false;
  }
}

function openCreate() {
  Object.assign(form, blankForm(game.value));
  if (usesCategory(game.value)) form.category = filterCategory.value || categoriesFor(game.value)[0] || '';
  if (filterLevel.value) form.level = filterLevel.value;
  if (filterKind.value) form.kind = filterKind.value;
  editor.value = { id: null };
}

function openEdit(card) {
  const choices = [...(card.data?.choices || [])];
  while (choices.length < 4) choices.push('');
  Object.assign(form, blankForm(card.game), {
    kind: card.kind || 'truth',
    level: card.level || 'light',
    category: card.category || '',
    body: card.body,
    pair: card.data?.[pairKey(card.game)] || '',
    roles: (card.data?.roles || []).join('\n'),
    taboo: (card.data?.taboo || []).join(', '),
    choices,
    answer: card.data?.answer ?? 0,
    note: card.data?.note || '',
    trip_id: card.trip_id || null,
    is_active: !!card.is_active,
  });
  editor.value = { id: card.id };
}

function payload() {
  const base = {
    game: form.game,
    body: form.body.trim(),
    kind: form.kind,
    level: form.level,
    category: form.category,
    trip_id: form.trip_id,
    is_active: form.is_active,
  };
  if (form.game === 'spyfall') base.data = { roles: form.roles.split(/\r?\n/) };
  if (pairKey(form.game)) base.data = { [pairKey(form.game)]: form.pair.trim() };
  if (form.game === 'heads_up') base.data = { taboo: form.taboo };
  if (form.game === 'trivia') {
    // ช่องว่างตรงกลางถูกตัดทิ้ง เลขข้อที่ถูกต้องเลื่อนตาม
    const filled = form.choices.map((c, i) => ({ c: c.trim(), i })).filter((x) => x.c);
    base.data = {
      choices: filled.map((x) => x.c),
      answer: filled.findIndex((x) => x.i === form.answer),
      note: form.note,
    };
  }
  return base;
}

async function save() {
  if (saving.value) return;
  saving.value = true;
  try {
    if (editor.value.id) {
      await api.put(`/admin/party-games/${editor.value.id}`, payload());
      toast.success('บันทึกแล้ว');
    } else {
      await api.post('/admin/party-games', payload());
      toast.success('เพิ่มเข้าคลังแล้ว');
    }
    game.value = form.game;
    editor.value = null;
    await load();
  } catch (e) {
    toast.error(errorText(e, 'บันทึกไม่สำเร็จ'));
  } finally {
    saving.value = false;
  }
}

function openBulk() {
  bulk.value = {
    game: game.value,
    kind: filterKind.value || 'truth',
    level: filterLevel.value || 'light',
    category: filterCategory.value || categoriesFor('heads_up')[0] || '',
    trip_id: null,
    lines: '',
  };
}

async function saveBulk() {
  if (saving.value || !bulk.value) return;
  saving.value = true;
  try {
    const res = await api.post('/admin/party-games/bulk', { ...bulk.value });
    toast.success(res.data.message || 'เพิ่มแล้ว');
    bulk.value = null;
    await load();
  } catch (e) {
    toast.error(errorText(e, 'เพิ่มไม่สำเร็จ'));
  } finally {
    saving.value = false;
  }
}

async function toggle(card) {
  try {
    await api.put(`/admin/party-games/${card.id}`, { is_active: !card.is_active });
    card.is_active = !card.is_active;
    toast.success(card.is_active ? 'เปิดในแอปแล้ว' : 'ซ่อนจากแอปแล้ว');
  } catch (e) {
    toast.error(errorText(e, 'บันทึกไม่สำเร็จ'));
  }
}

async function remove(card) {
  const res = await swal.confirm({
    title: 'ลบใบนี้?',
    text: `“${card.body}” — ถ้าแค่ไม่อยากให้โผล่ในแอป ใช้ปุ่มซ่อนแทน`,
    icon: 'warning',
    confirmText: 'ลบ',
  });
  if (!res.isConfirmed) return;
  try {
    await api.delete(`/admin/party-games/${card.id}`);
    cards.value = cards.value.filter((c) => c.id !== card.id);
    toast.success('ลบแล้ว');
  } catch (e) {
    toast.error(errorText(e, 'ลบไม่สำเร็จ'));
  }
}

onMounted(load);
</script>

<style scoped>
@import url('./admin-shared.css');

.pg-title-icon { color: var(--color-accent); font-size: 24px; margin-right: 4px; }
.pg-head-actions { display: flex; gap: 8px; flex-wrap: wrap; }

.pg-tips {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 12px;
  padding: 12px 16px;
  margin-bottom: 18px;
  font-size: 13px;
  line-height: 1.7;
  color: #166534;
}
.pg-tips > i { margin-top: 4px; }

.pg-tabs {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding-bottom: 4px;
  margin-bottom: 14px;
}
.pg-tab {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
  padding: 8px 14px;
  border: 1px solid #e5e7eb;
  border-radius: 999px;
  background: #fff;
  color: #374151;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}
.pg-tab.active { background: var(--color-accent); border-color: var(--color-accent); color: #fff; }
.pg-count { font-size: 12px; padding: 0 7px; border-radius: 999px; background: rgba(0, 0, 0, 0.08); }
.pg-tab.active .pg-count { background: rgba(255, 255, 255, 0.25); }

.pg-search-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: #9ca3af;
  font-size: 13px;
}

.pg-empty { text-align: center; padding: 48px 16px; color: #6b7280; }
.pg-empty > i { font-size: 40px; color: #d1d5db; display: block; margin-bottom: 12px; }

.pg-list { padding: 4px 0; }
.pg-summary { margin: 0; padding: 10px 18px; font-size: 12px; font-weight: 600; color: #6b7280; border-bottom: 1px solid #f3f4f6; }
.pg-row {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  padding: 14px 18px;
  border-bottom: 1px solid #f3f4f6;
}
.pg-row:last-child { border-bottom: none; }
.pg-off { opacity: 0.5; }
.pg-main { flex: 1; min-width: 0; }
.pg-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 4px; }
.pg-chip { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 6px; background: #f3f4f6; color: #4b5563; }
.pg-chip-trip { background: #e0f2fe; color: #0369a1; }
.pg-chip-off { background: #fee2e2; color: #b91c1c; }
.kind-truth { background: #e0e7ff; color: #4338ca; }
.kind-dare { background: #ffedd5; color: #c2410c; }
.level-light { background: #d1fae5; color: #047857; }
.level-medium { background: #fef3c7; color: #b45309; }
.level-spicy { background: #fce7f3; color: #be185d; }
.pg-body { margin: 0; font-size: 15px; font-weight: 600; color: #111827; overflow-wrap: anywhere; }
.pg-detail { margin: 4px 0 0; font-size: 13px; color: #4b5563; overflow-wrap: anywhere; }
.pg-detail-label { font-weight: 700; color: #6b7280; }
.pg-choices { margin: 6px 0 0; padding-left: 20px; font-size: 13px; color: #4b5563; }
.pg-choices li.correct { color: #047857; font-weight: 700; }

.pg-choice-input { display: flex; gap: 10px; align-items: center; margin-bottom: 8px; }
.pg-choice-input input[type='radio'] { width: 18px; height: 18px; flex-shrink: 0; accent-color: var(--color-accent); }
.pg-choice-input input:not([type='radio']) { flex: 1; }
.pg-optional { font-weight: 400; color: #9ca3af; }
.pg-prefixed { display: flex; align-items: center; gap: 8px; }
.pg-prefixed > span { flex-shrink: 0; font-weight: 700; color: #4b5563; }
.pg-prefixed > input { flex: 1; min-width: 0; }
.pg-hint code { background: #f3f4f6; padding: 1px 6px; border-radius: 4px; }
.pg-hint { font-size: 12px; color: #6b7280; margin: 0; }
.pg-check { display: flex; gap: 8px; align-items: center; font-size: 13px; color: #374151; cursor: pointer; }
.modal-footer { display: flex; gap: 8px; justify-content: flex-end; }

@media (max-width: 640px) {
  .pg-row { padding: 12px 14px; }
  .pg-head-actions { width: 100%; }
  .pg-head-actions > button { flex: 1; }
}
</style>
