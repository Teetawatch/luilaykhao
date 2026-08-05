<template>
  <Teleport to="body">
    <Transition name="consent-rise">
      <div
        v-if="visible"
        class="fixed bottom-0 left-0 right-0 z-[9999] p-3 sm:p-4 font-anuphan"
        role="dialog"
        aria-live="polite"
        aria-label="การตั้งค่าคุกกี้"
      >
        <div class="max-w-4xl mx-auto bg-white border border-gray-200 rounded-3xl p-5 sm:p-6">
          <div class="flex flex-col sm:flex-row sm:items-center gap-5">
            <div class="flex-1 min-w-0">
              <p class="font-black text-base text-gray-900 mb-1.5">เว็บนี้ใช้คุกกี้นะครับ 🍪</p>
              <p class="text-sm text-gray-500 leading-relaxed">
                เราอยากเก็บข้อมูลการใช้งานแบบไม่ระบุตัวตน เพื่อดูว่าหน้าไหนใช้ยาก
                แล้วปรับให้ดีขึ้น ถ้าไม่สะดวกกดปฏิเสธได้เลยครับ ใช้งานเว็บได้ครบเหมือนเดิม
                <router-link to="/privacy" class="text-teal-600 font-bold underline underline-offset-2 whitespace-nowrap">
                  อ่านนโยบายความเป็นส่วนตัว
                </router-link>
              </p>
            </div>

            <div class="flex items-center gap-2.5 shrink-0">
              <button
                type="button"
                @click="decide(false)"
                class="flex-1 sm:flex-none px-5 py-3 rounded-2xl font-bold text-sm text-gray-600 border border-gray-200 hover:bg-gray-50 transition-colors active:scale-95"
              >
                ปฏิเสธ
              </button>
              <button
                type="button"
                @click="decide(true)"
                class="flex-1 sm:flex-none px-6 py-3 rounded-2xl font-black text-sm bg-gray-900 text-white hover:bg-black transition-colors active:scale-95"
              >
                ยอมรับ
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { needsConsentDecision, setConsent, pageView } from '../lib/analytics';
import { useRoute } from 'vue-router';

const route = useRoute();
const visible = ref(false);

onMounted(() => {
  // Never shown when no tag is configured — there would be nothing to consent
  // to, and a cookie banner that controls nothing is just noise.
  visible.value = needsConsentDecision();
});

function decide(granted) {
  setConsent(granted);
  visible.value = false;

  // The router's page_view for the landing page was dropped while consent was
  // still unanswered. Send it now so an accepted session is not missing the
  // page the visitor actually arrived on.
  if (granted) {
    pageView(route.fullPath, document.title);
  }
}
</script>

<style scoped>
.consent-rise-enter-active,
.consent-rise-leave-active {
  transition: transform 0.35s ease, opacity 0.35s ease;
}
.consent-rise-enter-from,
.consent-rise-leave-to {
  transform: translateY(16px);
  opacity: 0;
}
</style>
