<template>
  <!--
    ดูรูป/วิดีโอจากรีวิวแบบเต็มจอ — ใช้ร่วมกันทั้งหน้ารีวิว หน้าแรก และหน้าทริป
    v-model = { items: [{ type: 'image'|'video', url }], index, author } หรือ null (ปิด)
  -->
  <Teleport to="body">
    <Transition name="rv-lightbox">
      <div
        v-if="modelValue"
        class="rv-lightbox"
        role="dialog"
        aria-modal="true"
        :aria-label="$t('รูปและวิดีโอจากรีวิว')"
        @click.self="close"
        @touchstart.passive="onTouchStart"
        @touchend="onTouchEnd"
      >
        <div class="rv-lightbox-top">
          <span class="truncate">{{ modelValue.author }}</span>
          <span v-if="items.length > 1" class="tabular-nums shrink-0">{{ modelValue.index + 1 }} / {{ items.length }}</span>
          <button ref="closeBtn" type="button" class="rv-lightbox-btn ml-auto" :aria-label="$t('ปิด')" @click="close">
            <span class="material-symbols-rounded">close</span>
          </button>
        </div>

        <button v-if="items.length > 1" type="button" class="rv-lightbox-btn rv-lightbox-prev" :aria-label="$t('ก่อนหน้า')" @click.stop="step(-1)">
          <span class="material-symbols-rounded">chevron_left</span>
        </button>

        <div class="rv-lightbox-figure" @click.self="close">
          <img v-if="current.type === 'image'" :key="`img-${modelValue.index}`" :src="current.url" alt="" class="rv-lightbox-media" />
          <video v-else :key="`vid-${modelValue.index}`" :src="current.url" class="rv-lightbox-media rv-lightbox-video" controls autoplay playsinline></video>
        </div>

        <button v-if="items.length > 1" type="button" class="rv-lightbox-btn rv-lightbox-next" :aria-label="$t('ถัดไป')" @click.stop="step(1)">
          <span class="material-symbols-rounded">chevron_right</span>
        </button>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { $t } from '@/i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
  modelValue: { type: Object, default: null },
});
const emit = defineEmits(['update:modelValue']);

const closeBtn = ref(null);
const items = computed(() => props.modelValue?.items || []);
const current = computed(() => items.value[props.modelValue?.index ?? 0] || {});
let lastFocused = null;

watch(
  () => !!props.modelValue,
  async (open) => {
    if (open) {
      lastFocused = document.activeElement;
      document.body.style.overflow = 'hidden';
      await nextTick();
      closeBtn.value?.focus();
    } else {
      document.body.style.overflow = '';
      lastFocused?.focus?.();
      lastFocused = null;
    }
  },
);

function close() {
  emit('update:modelValue', null);
}

function step(delta) {
  const total = items.value.length;
  if (!total) return;
  emit('update:modelValue', { ...props.modelValue, index: (props.modelValue.index + delta + total) % total });
}

function onKeydown(e) {
  if (!props.modelValue) return;
  if (e.key === 'Escape') close();
  else if (e.key === 'ArrowLeft') step(-1);
  else if (e.key === 'ArrowRight') step(1);
}

let touchX = null;
function onTouchStart(e) {
  touchX = e.touches[0]?.clientX ?? null;
}
function onTouchEnd(e) {
  if (touchX === null || items.value.length < 2) return;
  const dx = (e.changedTouches[0]?.clientX ?? touchX) - touchX;
  touchX = null;
  if (Math.abs(dx) > 50) step(dx < 0 ? 1 : -1);
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown);
  if (props.modelValue) document.body.style.overflow = '';
});
</script>

<style scoped>
.rv-lightbox {
  position: fixed;
  inset: 0;
  z-index: 300;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 72px 16px 24px;
  background: rgba(8, 12, 10, 0.94);
}
.rv-lightbox-top {
  position: absolute;
  top: 0;
  inset-inline: 0;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 12px 16px 12px 20px;
  color: rgba(255, 255, 255, 0.8);
  font-size: 14px;
  font-weight: 700;
}
.rv-lightbox-figure {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
}
.rv-lightbox-media {
  max-width: min(1100px, 100%);
  max-height: 100%;
  object-fit: contain;
  border-radius: 0.75rem;
}
.rv-lightbox-video {
  width: min(900px, 100%);
  background: #000;
}
.rv-lightbox-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: 9999px;
  color: #fff;
  background: rgba(255, 255, 255, 0.12);
  transition: background 0.2s ease;
}
.rv-lightbox-btn:hover { background: rgba(255, 255, 255, 0.24); }
.rv-lightbox-btn:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
.rv-lightbox-btn .material-symbols-rounded { font-size: 26px; }
.rv-lightbox-prev,
.rv-lightbox-next {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
}
.rv-lightbox-prev { left: 16px; }
.rv-lightbox-next { right: 16px; }
@media (max-width: 640px) {
  .rv-lightbox-prev,
  .rv-lightbox-next { display: none; } /* มือถือปัดซ้าย/ขวาแทน */
}

.rv-lightbox-enter-active, .rv-lightbox-leave-active { transition: opacity 0.2s ease; }
.rv-lightbox-enter-from, .rv-lightbox-leave-to { opacity: 0; }
</style>
