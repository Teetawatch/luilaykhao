// แปลอัตโนมัติ (แบบ Grab) ของกล่องแชทซัพพอร์ตบนเว็บ — ทั้งหน้าลูกค้า (/support)
// และหน้าแอดมิน (/admin/support) คำแปลมาจาก `translations` ใน payload ข้อความ,
// คำขอชุด (fetch → .../translations) และสัญญาณ `.support.message.translated`
// กติกาเดียวกับแอป (lib/services/chat_auto_translator.dart)
import { reactive } from 'vue';

const STRIP = /(https?:\/\/\S+)|(@\S+)/gu;
const PATTERNS = {
  th: /\p{Script=Thai}/gu,
  zh: /\p{Script=Han}/gu,
  latin: /\p{Script=Latin}/gu,
  other: /[\p{Script=Hiragana}\p{Script=Katakana}\p{Script=Hangul}\p{Script=Cyrillic}\p{Script=Arabic}\p{Script=Devanagari}]/gu,
};

/**
 * อักษรหลักของข้อความ — ตัวเดียวกับ ChatTranslationService::scriptOf และ
 * ChatScript.of ในแอป (แก้ที่หนึ่งต้องแก้ทุกที่)
 */
export function scriptOf(text) {
  const clean = String(text ?? '').replace(STRIP, ' ');
  let total = 0;
  let best = 'none';
  let bestCount = 0;
  for (const [key, re] of Object.entries(PATTERNS)) {
    const count = (clean.match(re) || []).length;
    total += count;
    if (count > bestCount) {
      best = key;
      bestCount = count;
    }
  }
  return total < 2 ? 'none' : best;
}

export function needsTranslation(text, lang) {
  switch (scriptOf(text)) {
    case 'none': return false;
    case 'th': return lang !== 'th';
    case 'zh': return lang !== 'zh';
    case 'latin': return lang !== 'en';
    default: return true;
  }
}

/**
 * @param {{ messages: import('vue').Ref<Array>, isMine: (m) => boolean, fetch: (lang: string, ids: number[]) => Promise<{translations?: object, pending?: number[]}> }} opts
 */
export function useChatAutoTranslate({ messages, isMine, fetch }) {
  const state = reactive({ available: false, enabled: false, lang: 'th', original: {} });
  const requested = new Set();
  const queue = new Set();
  let timer = null;

  const active = () => state.available && state.enabled;

  function applyMeta(meta) {
    if (!meta || typeof meta !== 'object') return;
    state.available = meta.available === true;
    state.enabled = meta.enabled !== false;
    if (['th', 'en', 'zh'].includes(meta.lang)) state.lang = meta.lang;
  }

  /** คำแปลที่ต้องแสดงแทนต้นฉบับ — null = แสดงต้นฉบับ */
  function translationOf(m) {
    if (!active() || !m || isMine(m) || m.is_deleted) return null;
    const t = m.translations?.[state.lang];
    return t?.text ? t.text : null;
  }

  function display(m) {
    const t = translationOf(m);
    return t && !state.original[m.id] ? t : m.body;
  }

  function toggle(id) {
    state.original[id] = !state.original[id];
  }

  function collect(list) {
    if (!active()) return;
    for (const m of list || []) {
      const body = String(m?.body ?? '').trim();
      if (!m?.id || !body || m.is_deleted || isMine(m) || requested.has(m.id)) continue;
      if (m.translations?.[state.lang]) continue;
      if (!needsTranslation(body, state.lang)) continue;
      queue.add(m.id);
    }
    if (!queue.size) return;
    clearTimeout(timer);
    timer = setTimeout(request, 400);
  }

  function put(id, translations, body = null) {
    const m = messages.value.find((x) => x.id === id);
    if (!m) return;
    if (body !== null && String(body).trim() !== String(m.body ?? '').trim()) return;
    const existing = m.translations && !Array.isArray(m.translations) ? m.translations : {};
    m.translations = { ...existing, ...translations };
  }

  async function request() {
    if (!active() || !queue.size) return;
    const lang = state.lang;
    const batch = [...queue].sort((a, b) => b - a).slice(0, 50);
    batch.forEach((id) => { queue.delete(id); requested.add(id); });
    try {
      const data = await fetch(lang, batch);
      if (lang !== state.lang) return;
      for (const [id, t] of Object.entries(data?.translations || {})) {
        put(Number(id), { [lang]: t });
      }
    } catch {
      // ขอไม่สำเร็จ — ข้อความชุดนี้ขอใหม่ได้ตอนโหลดอีกครั้ง
      batch.forEach((id) => requested.delete(id));
    }
    if (queue.size) request();
  }

  /** Echo `.support.message.translated` → {message_id, body, translations} */
  function onSignal(data) {
    if (!data?.message_id || !data.translations) return;
    put(Number(data.message_id), data.translations, data.body ?? null);
  }

  function dispose() {
    clearTimeout(timer);
  }

  return { state, applyMeta, collect, onSignal, translationOf, display, toggle, dispose };
}
