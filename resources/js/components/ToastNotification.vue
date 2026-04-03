<template>
  <Teleport to="body">
    <div class="fixed top-5 right-5 z-[9999] flex flex-col gap-3 pointer-events-none" aria-live="polite">
      <TransitionGroup name="toast">
        <div
          v-for="toast in toasts"
          :key="toast.id"
          class="pointer-events-auto flex items-start gap-3 min-w-[280px] max-w-[360px] px-4 py-3.5 rounded-2xl shadow-xl backdrop-blur-md text-sm font-medium select-none cursor-default"
          :class="toastClass(toast.type)"
        >
          <span class="material-symbols-rounded text-[20px] shrink-0 mt-0.5" style="font-variation-settings:'FILL' 1,'wght' 400,'GRAD' 0,'opsz' 24">
            {{ toastIcon(toast.type) }}
          </span>
          <span class="flex-1 leading-snug">{{ toast.message }}</span>
          <button
            class="shrink-0 opacity-60 hover:opacity-100 transition-opacity ml-1"
            @click="remove(toast.id)"
            aria-label="ปิด"
          >
            <span class="material-symbols-rounded text-[18px]">close</span>
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<script setup>
import { useToast } from '../lib/toast';

const { toasts, remove } = useToast();

function toastIcon(type) {
  return {
    success: 'check_circle',
    error:   'cancel',
    warning: 'warning',
    info:    'info',
  }[type] ?? 'info';
}

function toastClass(type) {
  return {
    success: 'bg-emerald-600/95 text-white',
    error:   'bg-red-600/95 text-white',
    warning: 'bg-amber-500/95 text-white',
    info:    'bg-[#006565]/95 text-white',
  }[type] ?? 'bg-gray-800/95 text-white';
}
</script>

<style scoped>
.toast-enter-active {
  animation: toast-in 0.28s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.toast-leave-active {
  animation: toast-out 0.22s ease-in forwards;
}
@keyframes toast-in {
  from { opacity: 0; transform: translateX(60px) scale(0.92); }
  to   { opacity: 1; transform: translateX(0)    scale(1); }
}
@keyframes toast-out {
  from { opacity: 1; transform: translateX(0)    scale(1); }
  to   { opacity: 0; transform: translateX(60px) scale(0.9); }
}
</style>
