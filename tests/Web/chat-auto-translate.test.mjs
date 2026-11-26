// แปลอัตโนมัติของกล่องแชทซัพพอร์ตบนเว็บ — อักษรหลักต้องตรงกับ
// ChatTranslationService::scriptOf (PHP) และ ChatScript.of (แอป)
import assert from 'node:assert/strict';
import { ref } from 'vue';
import { needsTranslation, scriptOf, useChatAutoTranslate } from '../../resources/js/lib/chatAutoTranslate.js';

let passed = 0;
async function test(name, fn) {
  await fn();
  passed++;
  console.log(`  ✓ ${name}`);
}

await test('อักษรหลักตรงกับฝั่งเซิร์ฟเวอร์', () => {
  assert.equal(scriptOf('รถตู้ arrive 8 โมงนะ'), 'th');
  assert.equal(scriptOf('See you @สมชาย https://example.com/ที่'), 'latin');
  assert.equal(scriptOf('明天几点出发？'), 'zh');
  assert.equal(scriptOf('ありがとう'), 'other');
  assert.equal(scriptOf('👍 555 08:30'), 'none');
  assert.equal(needsTranslation('Thanks!', 'en'), false);
  assert.equal(needsTranslation('Thanks!', 'th'), true);
  assert.equal(needsTranslation('ありがとう', 'zh'), true);
});

await test('ขอคำแปลข้อความของอีกฝั่งที่ยังไม่มี แล้วแสดงแทนต้นฉบับ', async () => {
  const messages = ref([
    { id: 1, body: 'Can I book for a friend?', sender_role: 'customer', translations: [] },
    { id: 2, body: 'ได้ครับ', sender_role: 'admin', translations: [] },
  ]);
  const asked = [];
  const t = useChatAutoTranslate({
    messages,
    isMine: (m) => m.sender_role === 'admin',
    fetch: async (lang, ids) => {
      asked.push([lang, ids]);
      return { translations: { 1: { text: 'จองให้เพื่อนได้ไหม' } }, pending: [] };
    },
  });
  t.applyMeta({ available: true, enabled: true, lang: 'th' });
  t.collect(messages.value);
  await new Promise((r) => setTimeout(r, 450));

  assert.deepEqual(asked, [['th', [1]]]);
  assert.equal(t.display(messages.value[0]), 'จองให้เพื่อนได้ไหม');
  assert.equal(t.display(messages.value[1]), 'ได้ครับ');
  t.toggle(1);
  assert.equal(t.display(messages.value[0]), 'Can I book for a friend?');
});

await test('คำแปลจาก realtime ที่ไม่ตรงกับเนื้อหาปัจจุบันถูกทิ้ง', () => {
  const messages = ref([{ id: 5, body: 'ถึงแล้วครับ', sender_role: 'admin' }]);
  const t = useChatAutoTranslate({ messages, isMine: (m) => m.sender_role === 'customer', fetch: async () => ({}) });
  t.applyMeta({ available: true, enabled: true, lang: 'en' });

  t.onSignal({ message_id: 5, body: 'ข้อความเก่า', translations: { en: { text: 'Old' } } });
  assert.equal(t.display(messages.value[0]), 'ถึงแล้วครับ');
  t.onSignal({ message_id: 5, body: 'ถึงแล้วครับ', translations: { en: { text: 'We are here.' } } });
  assert.equal(t.display(messages.value[0]), 'We are here.');
});

console.log(`\nchat auto-translate: ${passed} passed`);
