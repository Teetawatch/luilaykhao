<template>
  <div class="top-banner-root bg-[#0D2B1E] text-white relative z-[60] overflow-hidden">
    <!-- Subtle Background Pattern/Gradient -->
    <div class="absolute inset-0 bg-gradient-to-r from-primary via-primary-mid to-primary opacity-90"></div>
    
    <div class="max-w-7xl mx-auto px-4 h-9 flex items-center justify-center relative z-10">
      <div class="flex items-center gap-8 overflow-hidden">
        <Transition name="fade-slide" mode="out-in">
          <div :key="currentMessage" class="flex items-center gap-2.5">
            <span class="text-base leading-none">{{ messages[currentMessage].emoji }}</span>
            <span class="text-[12px] font-bold tracking-wide uppercase font-anuphan">{{ messages[currentMessage].text }}</span>
          </div>
        </Transition>
      </div>
      
      <!-- Close button (Optional - but makes it feel premium) -->
      <!-- <button class="absolute right-4 text-white/50 hover:text-white transition-colors">
        <span class="material-symbols-rounded text-[16px]">close</span>
      </button> -->
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';

const messages = [
  { emoji: '🔥', text: 'โปรลดราคา ประจำเดือนเมษายน!' },
  { emoji: '🏔️', text: 'เปิดจองทริปใหม่ สัมผัสธรรมชาติกับลุยเลเขา' },
  { emoji: '✨', text: 'สมัครสมาชิกวันนี้ รับส่วนลดพิเศษทันที' }
];

const currentMessage = ref(0);
let interval = null;

onMounted(() => {
  interval = setInterval(() => {
    currentMessage.value = (currentMessage.value + 1) % messages.length;
  }, 4000);
});

onUnmounted(() => {
  if (interval) clearInterval(interval);
});
</script>

<style scoped>
.fade-slide-enter-active,
.fade-slide-leave-active {
  transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
}

.fade-slide-enter-from {
  opacity: 0;
  transform: translateY(10px);
}

.fade-slide-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}

.top-banner-root {
  box-shadow: 0 1px 0 0 rgba(255, 255, 255, 0.05);
}
</style>
